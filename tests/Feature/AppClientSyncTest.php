<?php

namespace Tests\Feature;

use App\Jobs\SyncAppClientFromGithub;
use App\Models\AppClient;
use App\Models\User;
use App\Services\AppClientArtifactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class AppClientSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_job_quarantines_matching_asset_without_publishing_it(): void
    {
        $this->fakeDisks();
        $contents = $this->payload('candidate');
        $client = $this->githubClient();

        $this->fakeRelease($contents);
        SyncAppClientFromGithub::dispatchSync($client->id);

        $client->refresh();
        $this->assertNull($client->synced_version);
        $this->assertSame('', $client->url);
        $this->assertNull($client->local_path);
        $this->assertSame('7.23.3', $client->pending_version);
        $this->assertSame(hash('sha256', $contents), $client->pending_sha256);
        $this->assertSame(strlen($contents), $client->pending_size);
        $this->assertSame(hash('sha256', $contents), $client->pending_source_digest);
        Storage::disk('local')->assertExists($client->pending_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_sync_job_rejects_a_path_traversal_asset_name(): void
    {
        $this->fakeDisks();
        $contents = $this->payload('evil');
        $client = $this->githubClient(['asset_pattern' => '/payload\.zip$/']);

        $this->fakeRelease($contents, [
            'name' => '../../../../payload.zip',
            'browser_download_url' => 'https://github.com/2dust/v2rayN/releases/download/7.23.3/payload.zip',
        ]);
        SyncAppClientFromGithub::dispatchSync($client->id);

        $client->refresh();
        $this->assertNull($client->pending_path);
        $this->assertStringContainsString('文件名', $client->last_sync_error);
        Http::assertSentCount(1);
    }

    public function test_sync_job_rejects_download_url_outside_configured_repository(): void
    {
        $this->fakeDisks();
        $contents = $this->payload('wrong-repo');
        $client = $this->githubClient();

        $this->fakeRelease($contents, [
            'browser_download_url' => 'https://github.com/attacker/repo/releases/download/7.23.3/v2rayN-windows-64.zip',
        ]);
        SyncAppClientFromGithub::dispatchSync($client->id);

        $client->refresh();
        $this->assertNull($client->pending_path);
        $this->assertStringContainsString('不属于', $client->last_sync_error);
        Http::assertSentCount(1);
    }

    public function test_sync_job_rejects_asset_larger_than_limit_before_download(): void
    {
        $this->fakeDisks();
        $client = $this->githubClient();

        $this->fakeRelease($this->payload('unused'), [
            'size' => AppClient::MAX_ARTIFACT_BYTES + 1,
        ]);
        SyncAppClientFromGithub::dispatchSync($client->id);

        $client->refresh();
        $this->assertNull($client->pending_path);
        $this->assertStringContainsString('大小', $client->last_sync_error);
        Http::assertSentCount(1);
    }

    public function test_sync_job_rejects_actual_size_mismatch(): void
    {
        $this->fakeDisks();
        $contents = $this->payload('short');
        $client = $this->githubClient();

        $this->fakeRelease($contents, ['size' => strlen($contents) + 50]);
        SyncAppClientFromGithub::dispatchSync($client->id);

        $client->refresh();
        $this->assertNull($client->pending_path);
        $this->assertStringContainsString('实际大小', $client->last_sync_error);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_sync_job_rejects_sha256_mismatch(): void
    {
        $this->fakeDisks();
        $contents = $this->payload('digest-mismatch');
        $client = $this->githubClient();

        $this->fakeRelease($contents, ['digest' => 'sha256:'.str_repeat('a', 64)]);
        SyncAppClientFromGithub::dispatchSync($client->id);

        $client->refresh();
        $this->assertNull($client->pending_path);
        $this->assertStringContainsString('摘要不一致', $client->last_sync_error);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_same_release_tag_with_changed_digest_is_not_replaced(): void
    {
        $this->fakeDisks();
        $oldContents = $this->payload('published');
        Storage::disk('public')->put('app-downloads/current.zip', $oldContents);
        $client = $this->githubClient([
            'url' => 'https://example.com/storage/app-downloads/current.zip',
            'local_path' => 'app-downloads/current.zip',
            'synced_version' => '7.23.3',
            'published_sha256' => hash('sha256', $oldContents),
            'published_size' => strlen($oldContents),
        ]);
        $newContents = $this->payload('replaced-under-same-tag');

        $this->fakeRelease($newContents);
        SyncAppClientFromGithub::dispatchSync($client->id);

        $client->refresh();
        $this->assertStringContainsString('已变化', $client->last_sync_error);
        $this->assertSame($oldContents, Storage::disk('public')->get($client->local_path));
        $this->assertNull($client->pending_path);
        Http::assertSentCount(1);
    }

    public function test_sync_job_skips_when_published_latest_digest_is_unchanged(): void
    {
        $this->fakeDisks();
        $contents = $this->payload('published');
        $client = $this->githubClient([
            'url' => 'https://example.com/storage/app-downloads/current.zip',
            'synced_version' => '7.23.3',
            'published_sha256' => hash('sha256', $contents),
            'published_size' => strlen($contents),
        ]);

        $this->fakeRelease($contents);
        SyncAppClientFromGithub::dispatchSync($client->id);

        $client->refresh();
        $this->assertSame('https://example.com/storage/app-downloads/current.zip', $client->url);
        $this->assertNull($client->last_sync_error);
        Http::assertSentCount(1);
    }

    public function test_sync_job_does_nothing_when_no_asset_matches_pattern(): void
    {
        $this->fakeDisks();
        $client = $this->githubClient(['asset_pattern' => '/^does-not-exist\.zip$/']);

        Http::fake([
            'api.github.com/*' => Http::response([
                'tag_name' => '7.23.3',
                'assets' => [[
                    'name' => 'v2rayN-windows-64.zip',
                    'browser_download_url' => $this->assetUrl(),
                    'size' => 2048,
                    'digest' => 'sha256:'.str_repeat('a', 64),
                ]],
            ]),
        ]);

        SyncAppClientFromGithub::dispatchSync($client->id);

        $client->refresh();
        $this->assertNull($client->pending_version);
        $this->assertStringContainsString('没有文件匹配', $client->last_sync_error);
    }

    public function test_wrong_trusted_hash_cannot_publish_candidate(): void
    {
        $this->fakeDisks();
        $oldContents = $this->payload('old');
        Storage::disk('public')->put('app-downloads/old.zip', $oldContents);
        $client = $this->githubClient([
            'url' => 'https://example.com/storage/app-downloads/old.zip',
            'local_path' => 'app-downloads/old.zip',
            'synced_version' => '7.0.0',
        ]);
        $contents = $this->payload('candidate');

        $this->fakeRelease($contents);
        SyncAppClientFromGithub::dispatchSync($client->id);

        try {
            app(AppClientArtifactService::class)->approve($client, str_repeat('0', 64));
            $this->fail('A mismatched trusted hash must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('不一致', $exception->getMessage());
        }

        $client->refresh();
        $this->assertSame('7.0.0', $client->synced_version);
        $this->assertSame($oldContents, Storage::disk('public')->get('app-downloads/old.zip'));
        Storage::disk('local')->assertExists($client->pending_path);
    }

    public function test_correct_trusted_hash_atomically_publishes_and_deletes_old_file(): void
    {
        $this->fakeDisks();
        Storage::disk('public')->put('app-downloads/old.zip', $this->payload('old'));
        $client = $this->githubClient([
            'url' => 'https://example.com/storage/app-downloads/old.zip',
            'local_path' => 'app-downloads/old.zip',
            'synced_version' => '7.0.0',
        ]);
        $contents = $this->payload('approved');

        $this->fakeRelease($contents);
        SyncAppClientFromGithub::dispatchSync($client->id);
        $trustedSha256 = $client->fresh()->pending_sha256;

        app(AppClientArtifactService::class)->approve($client, $trustedSha256);

        $client->refresh();
        $this->assertSame('7.23.3', $client->synced_version);
        $this->assertSame($trustedSha256, $client->published_sha256);
        $this->assertSame(strlen($contents), $client->published_size);
        $this->assertNotNull($client->published_verified_at);
        $this->assertNull($client->pending_path);
        $this->assertStringContainsString('/storage/app-downloads/', $client->url);
        Storage::disk('public')->assertMissing('app-downloads/old.zip');
        Storage::disk('public')->assertExists($client->local_path);
        $this->assertSame($contents, Storage::disk('public')->get($client->local_path));
    }

    public function test_existing_published_file_can_establish_trusted_hash_baseline(): void
    {
        $this->fakeDisks();
        $contents = $this->payload('legacy-published');
        Storage::disk('public')->put('app-downloads/current.zip', $contents);
        $client = $this->githubClient([
            'url' => 'https://example.com/storage/app-downloads/current.zip',
            'local_path' => 'app-downloads/current.zip',
            'synced_version' => '7.23.3',
            'published_sha256' => hash('sha256', $contents),
            'published_size' => strlen($contents),
        ]);

        app(AppClientArtifactService::class)->verifyPublished($client, hash('sha256', $contents));

        $client->refresh();
        $this->assertNotNull($client->published_verified_at);
        $this->assertSame(hash('sha256', $contents), $client->published_sha256);
    }

    public function test_admin_can_add_a_github_sourced_client_and_it_gets_dispatched_for_sync(): void
    {
        Bus::fake();
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test('admin.system')
            ->set('newAppClientPlatform', AppClient::PLATFORM_WINDOWS)
            ->set('newAppClientName', 'v2rayN')
            ->set('newAppClientSourceType', AppClient::SOURCE_GITHUB)
            ->set('newAppClientGithubRepo', '2dust/v2rayN')
            ->set('newAppClientAssetPattern', '/^v2rayN-windows-64\.zip$/')
            ->call('addAppClient');

        $this->assertDatabaseHas('app_clients', [
            'name' => 'v2rayN',
            'source_type' => 'github',
            'github_repo' => '2dust/v2rayN',
        ]);
        Bus::assertDispatched(SyncAppClientFromGithub::class);
    }

    public function test_admin_can_approve_a_pending_release_with_trusted_hash(): void
    {
        $this->fakeDisks();
        $admin = User::factory()->create(['is_admin' => true]);
        $contents = $this->payload('admin-approved');
        $client = $this->githubClient();
        $this->fakeRelease($contents);
        SyncAppClientFromGithub::dispatchSync($client->id);
        $hash = $client->fresh()->pending_sha256;

        Livewire::actingAs($admin)->test('admin.system')
            ->set("appClientApprovalHashes.{$client->id}", $hash)
            ->call('approveAppClientRelease', $client->id)
            ->assertHasNoErrors()
            ->assertSet('successMessage', fn ($message) => str_contains($message, '已发布'));

        $this->assertSame($hash, $client->fresh()->published_sha256);
    }

    public function test_admin_can_trigger_an_immediate_resync(): void
    {
        Bus::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $client = $this->githubClient();

        Livewire::actingAs($admin)->test('admin.system')
            ->call('syncAppClientNow', $client->id);

        Bus::assertDispatched(
            SyncAppClientFromGithub::class,
            fn ($job) => $job->appClientId === $client->id
        );
    }

    public function test_removing_a_github_client_deletes_public_and_quarantined_files(): void
    {
        $this->fakeDisks();
        Storage::disk('public')->put('app-downloads/1-file.zip', $this->payload('published'));
        Storage::disk('local')->put('app-client-quarantine/1/candidate.zip', $this->payload('pending'));
        $admin = User::factory()->create(['is_admin' => true]);
        $client = $this->githubClient([
            'url' => 'https://example.com/storage/app-downloads/1-file.zip',
            'local_path' => 'app-downloads/1-file.zip',
            'pending_path' => 'app-client-quarantine/1/candidate.zip',
            'pending_version' => '8.0.0',
            'pending_sha256' => str_repeat('a', 64),
            'pending_size' => 2048,
        ]);

        Livewire::actingAs($admin)->test('admin.system')
            ->call('removeAppClient', $client->id);

        $this->assertDatabaseMissing('app_clients', ['id' => $client->id]);
        Storage::disk('public')->assertMissing('app-downloads/1-file.zip');
        Storage::disk('local')->assertMissing('app-client-quarantine/1/candidate.zip');
    }

    private function fakeDisks(): void
    {
        Storage::fake('local');
        Storage::fake('public');
    }

    private function githubClient(array $overrides = []): AppClient
    {
        return AppClient::create(array_merge([
            'platform' => AppClient::PLATFORM_WINDOWS,
            'name' => 'v2rayN',
            'url' => '',
            'source_type' => AppClient::SOURCE_GITHUB,
            'github_repo' => '2dust/v2rayN',
            'asset_pattern' => '/^v2rayN-windows-64\.zip$/',
        ], $overrides));
    }

    private function fakeRelease(string $contents, array $assetOverrides = []): void
    {
        $asset = array_merge([
            'name' => 'v2rayN-windows-64.zip',
            'browser_download_url' => $this->assetUrl(),
            'size' => strlen($contents),
            'digest' => 'sha256:'.hash('sha256', $contents),
        ], $assetOverrides);

        Http::fake([
            'api.github.com/*' => Http::response([
                'tag_name' => '7.23.3',
                'assets' => [$asset],
            ]),
            'github.com/2dust/v2rayN/releases/download/*' => Http::response($contents),
        ]);
    }

    private function assetUrl(): string
    {
        return 'https://github.com/2dust/v2rayN/releases/download/7.23.3/v2rayN-windows-64.zip';
    }

    private function payload(string $marker): string
    {
        return str_repeat($marker.'-', (int) ceil(2048 / (strlen($marker) + 1)));
    }
}
