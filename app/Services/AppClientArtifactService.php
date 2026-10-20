<?php

namespace App\Services;

use App\Models\AppClient;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class AppClientArtifactService
{
    public function verifyPublished(AppClient $client, string $trustedSha256): void
    {
        $client->refresh();
        $trustedSha256 = $this->normalizeTrustedHash($trustedSha256);

        if (! $client->local_path) {
            throw new RuntimeException('当前没有已发布的本地客户端文件。');
        }

        $publicPath = Storage::disk('public')->path($client->local_path);

        if (! is_file($publicPath)) {
            throw new RuntimeException('已发布文件不存在，请先重新同步。');
        }

        $actualSize = filesize($publicPath);
        $actualSha256 = hash_file('sha256', $publicPath);

        if ($actualSize === false || ! is_string($actualSha256)) {
            throw new RuntimeException('无法读取已发布文件。');
        }

        if (! hash_equals($actualSha256, $trustedSha256)) {
            throw new RuntimeException('SHA-256 不一致，当前文件未标记为可信。');
        }

        $client->update([
            'published_sha256' => $actualSha256,
            'published_size' => $actualSize,
            'published_verified_at' => now(),
            'last_sync_error' => null,
        ]);
    }

    public function approve(AppClient $client, string $trustedSha256): void
    {
        $client->refresh();
        $trustedSha256 = $this->normalizeTrustedHash($trustedSha256);

        if (! $client->hasPendingRelease()) {
            throw new RuntimeException('没有待审核的客户端版本。');
        }

        if (! hash_equals((string) $client->pending_sha256, $trustedSha256)) {
            throw new RuntimeException('SHA-256 不一致，候选文件未发布。');
        }

        $privatePath = Storage::disk('local')->path($client->pending_path);

        if (! is_file($privatePath)) {
            throw new RuntimeException('隔离区中的候选文件不存在，请重新同步。');
        }

        $actualSize = filesize($privatePath);
        $actualSha256 = hash_file('sha256', $privatePath);

        if ($actualSize === false
            || $actualSize !== $client->pending_size
            || ! is_string($actualSha256)
            || ! hash_equals((string) $client->pending_sha256, $actualSha256)) {
            throw new RuntimeException('候选文件在审核前发生变化，已拒绝发布。');
        }

        $publicDisk = Storage::disk('public');
        $publicDisk->makeDirectory('app-downloads');
        $newPath = 'app-downloads/'.basename($client->pending_path);
        $publicPath = $publicDisk->path($newPath);

        if (is_file($publicPath)) {
            throw new RuntimeException('目标文件已存在，请重新同步后再审核。');
        }

        if (! @rename($privatePath, $publicPath)) {
            if (! @copy($privatePath, $publicPath) || ! @unlink($privatePath)) {
                @unlink($publicPath);
                throw new RuntimeException('候选文件无法移入公开下载目录。');
            }
        }

        @chmod($publicPath, 0644);
        $oldPath = $client->local_path;

        try {
            $client->update([
                'url' => $publicDisk->url($newPath),
                'local_path' => $newPath,
                'synced_version' => $client->pending_version,
                'synced_at' => now(),
                'published_sha256' => $actualSha256,
                'published_size' => $actualSize,
                'published_verified_at' => now(),
                'pending_path' => null,
                'pending_version' => null,
                'pending_sha256' => null,
                'pending_size' => null,
                'pending_source_digest' => null,
                'pending_at' => null,
                'last_sync_error' => null,
            ]);
        } catch (Throwable $exception) {
            @unlink($publicPath);
            throw $exception;
        }

        if ($oldPath && $oldPath !== $newPath) {
            $publicDisk->delete($oldPath);
        }
    }

    public function reject(AppClient $client): void
    {
        $client->refresh();

        if ($client->pending_path) {
            Storage::disk('local')->delete($client->pending_path);
        }

        $client->update([
            'pending_path' => null,
            'pending_version' => null,
            'pending_sha256' => null,
            'pending_size' => null,
            'pending_source_digest' => null,
            'pending_at' => null,
        ]);
    }

    private function normalizeTrustedHash(string $trustedSha256): string
    {
        $trustedSha256 = strtolower(trim($trustedSha256));

        if (! preg_match('/\A[a-f0-9]{64}\z/', $trustedSha256)) {
            throw new RuntimeException('请输入可信来源提供的完整 SHA-256。');
        }

        return $trustedSha256;
    }
}
