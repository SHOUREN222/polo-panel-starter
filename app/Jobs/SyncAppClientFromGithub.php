<?php

namespace App\Jobs;

use App\Models\AppClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LengthException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Downloads a GitHub release into private quarantine. A separate, explicit
 * approval compares the calculated digest with a trusted out-of-band SHA-256
 * before the candidate can replace the currently published client.
 */
class SyncAppClientFromGithub implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $appClientId) {}

    public function uniqueId(): string
    {
        return (string) $this->appClientId;
    }

    public function handle(): void
    {
        $client = AppClient::find($this->appClientId);

        if (! $client || $client->source_type !== AppClient::SOURCE_GITHUB || ! $client->github_repo || ! $client->asset_pattern) {
            return;
        }

        $client->update([
            'last_sync_attempt_at' => now(),
            'last_sync_error' => null,
        ]);

        try {
            $release = Http::withHeaders([
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'Polo-Panel',
            ])->connectTimeout(10)->timeout(30)
                ->get("https://api.github.com/repos/{$client->github_repo}/releases/latest");
        } catch (Throwable $exception) {
            $this->recordFailure($client, '无法连接 GitHub Release API。', ['exception' => $exception->getMessage()]);

            return;
        }

        if (! $release->ok()) {
            $this->recordFailure($client, 'GitHub Release API 返回异常状态。', ['status' => $release->status()]);

            return;
        }

        $tag = $release->json('tag_name');

        if (! is_string($tag) || trim($tag) === '') {
            $this->recordFailure($client, 'GitHub Release 缺少版本标签。');

            return;
        }

        $asset = collect($release->json('assets', []))->first(
            fn (mixed $asset) => is_array($asset)
                && isset($asset['name'])
                && is_string($asset['name'])
                && @preg_match($client->asset_pattern, $asset['name']) === 1,
        );

        if (! is_array($asset)) {
            $this->recordFailure($client, '没有文件匹配已配置的资产规则。', ['pattern' => $client->asset_pattern]);

            return;
        }

        $assetName = $asset['name'];
        $assetUrl = $asset['browser_download_url'] ?? null;
        $declaredSize = $asset['size'] ?? null;
        $extension = strtolower(pathinfo($assetName, PATHINFO_EXTENSION));

        if (basename($assetName) !== $assetName
            || ! in_array($extension, AppClient::ALLOWED_ARTIFACT_EXTENSIONS, true)) {
            $this->recordFailure($client, 'GitHub 资产文件名或扩展名不安全。');

            return;
        }

        if (! is_string($assetUrl) || ! $this->isTrustedAssetUrl($assetUrl, $client->github_repo)) {
            $this->recordFailure($client, 'GitHub 资产下载地址不属于已配置仓库。');

            return;
        }

        if (! is_int($declaredSize)
            || $declaredSize < AppClient::MIN_ARTIFACT_BYTES
            || $declaredSize > AppClient::MAX_ARTIFACT_BYTES) {
            $this->recordFailure($client, 'GitHub 资产大小超出允许范围。', ['declared_size' => $declaredSize]);

            return;
        }

        $sourceDigest = $this->sourceDigest($asset['digest'] ?? null);

        if (($asset['digest'] ?? null) !== null && $sourceDigest === null) {
            $this->recordFailure($client, 'GitHub 资产摘要格式无效。');

            return;
        }

        // A release asset replaced under the same tag is suspicious. Keep the
        // already-published file and surface the mismatch for manual review.
        if ($tag === $client->synced_version) {
            if ($sourceDigest !== null
                && $client->published_sha256 !== null
                && ! hash_equals($client->published_sha256, $sourceDigest)) {
                $this->recordFailure($client, '同一版本的 GitHub 资产 SHA-256 已变化，已拒绝替换。');
            }

            return;
        }

        if ($tag === $client->pending_version
            && $client->pending_path
            && Storage::disk('local')->exists($client->pending_path)
            && $client->pending_size === $declaredSize
            && ($sourceDigest === null || $client->pending_source_digest === $sourceDigest)) {
            return;
        }

        $quarantineDirectory = 'app-client-quarantine/'.$client->id;
        $temporaryPath = $quarantineDirectory.'/'.Str::uuid().'.part';
        $privateDisk = Storage::disk('local');
        $privateDisk->makeDirectory($quarantineDirectory);
        @chmod($privateDisk->path($quarantineDirectory), 0700);
        $absolutePath = $privateDisk->path($temporaryPath);

        try {
            $download = Http::withHeaders(['User-Agent' => 'Polo-Panel'])
                ->connectTimeout(15)
                ->timeout(900)
                ->withOptions([
                    'sink' => $absolutePath,
                    'allow_redirects' => ['protocols' => ['https']],
                    'on_headers' => function (ResponseInterface $response): void {
                        $length = $response->getHeaderLine('Content-Length');

                        if ($length !== '' && (int) $length > AppClient::MAX_ARTIFACT_BYTES) {
                            throw new LengthException('Client artifact exceeds the download limit.');
                        }
                    },
                    'progress' => function ($downloadTotal, $downloadedBytes): void {
                        if ($downloadTotal > AppClient::MAX_ARTIFACT_BYTES
                            || $downloadedBytes > AppClient::MAX_ARTIFACT_BYTES) {
                            throw new LengthException('Client artifact exceeds the download limit.');
                        }
                    },
                ])
                ->get($assetUrl);
        } catch (Throwable $exception) {
            $privateDisk->delete($temporaryPath);
            $this->recordFailure($client, 'GitHub 资产下载失败或超过大小限制。', ['exception' => $exception->getMessage()]);

            return;
        }

        if (! $download->ok() || ! is_file($absolutePath)) {
            $privateDisk->delete($temporaryPath);
            $this->recordFailure($client, 'GitHub 资产下载返回异常状态。', ['status' => $download->status()]);

            return;
        }

        $actualSize = filesize($absolutePath);
        $actualSha256 = hash_file('sha256', $absolutePath);

        if ($actualSize === false
            || $actualSize !== $declaredSize
            || $actualSize < AppClient::MIN_ARTIFACT_BYTES
            || $actualSize > AppClient::MAX_ARTIFACT_BYTES
            || ! is_string($actualSha256)) {
            $privateDisk->delete($temporaryPath);
            $this->recordFailure($client, '下载文件的实际大小与 GitHub 元数据不一致。');

            return;
        }

        if ($sourceDigest !== null && ! hash_equals($sourceDigest, $actualSha256)) {
            $privateDisk->delete($temporaryPath);
            $this->recordFailure($client, '下载文件与 GitHub SHA-256 摘要不一致。');

            return;
        }

        $safeTag = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $tag), '-.');
        $safeTag = substr($safeTag !== '' ? $safeTag : 'release', 0, 48);
        $pendingPath = $quarantineDirectory.'/'.$client->id.'-'.$safeTag.'-'.substr($actualSha256, 0, 16).'.'.$extension;
        $privateDisk->delete($pendingPath);

        if (! $privateDisk->move($temporaryPath, $pendingPath)) {
            $privateDisk->delete($temporaryPath);
            $this->recordFailure($client, '下载文件无法移入私有隔离区。');

            return;
        }

        @chmod($privateDisk->path($pendingPath), 0600);
        $oldPendingPath = $client->pending_path;

        try {
            $client->update([
                'pending_path' => $pendingPath,
                'pending_version' => $tag,
                'pending_sha256' => $actualSha256,
                'pending_size' => $actualSize,
                'pending_source_digest' => $sourceDigest,
                'pending_at' => now(),
                'last_sync_error' => null,
            ]);
        } catch (Throwable $exception) {
            $privateDisk->delete($pendingPath);
            throw $exception;
        }

        if ($oldPendingPath && $oldPendingPath !== $pendingPath) {
            $privateDisk->delete($oldPendingPath);
        }
    }

    private function isTrustedAssetUrl(string $url, string $repository): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'github.com'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])) {
            return false;
        }

        $expectedPrefix = '/'.strtolower(trim($repository, '/')).'/releases/download/';

        return str_starts_with(strtolower((string) ($parts['path'] ?? '')), $expectedPrefix);
    }

    private function sourceDigest(mixed $digest): ?string
    {
        if ($digest === null || $digest === '') {
            return null;
        }

        if (! is_string($digest) || ! preg_match('/\Asha256:([a-f0-9]{64})\z/i', $digest, $matches)) {
            return null;
        }

        return strtolower($matches[1]);
    }

    private function recordFailure(AppClient $client, string $message, array $context = []): void
    {
        $client->update(['last_sync_error' => $message]);

        Log::warning('SyncAppClientFromGithub: '.$message, [
            'client_id' => $client->id,
            'repo' => $client->github_repo,
            ...$context,
        ]);
    }
}
