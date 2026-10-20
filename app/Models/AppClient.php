<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A third-party proxy client the admin recommends for a given platform
 * (e.g. Clash Verge for Windows, Shadowrocket for iOS) — this panel doesn't
 * ship its own app, so unlike a first-party download this has no version
 * number to track, just a name and a link, and a platform can have several.
 *
 * source_type=github clients are mirrored locally for reliable downloads.
 * New releases stay in private quarantine until an administrator compares
 * their SHA-256 against a trusted, independent publisher source.
 */
class AppClient extends Model
{
    // 客户端下载列表是平台共享内容：分站也从 central 主库读。
    use CentralConnection;

    public const PLATFORM_WINDOWS = 'windows';

    public const PLATFORM_MACOS = 'macos';

    public const PLATFORM_IOS = 'ios';

    public const PLATFORM_ANDROID = 'android';

    public const PLATFORMS = [
        self::PLATFORM_WINDOWS => 'Windows',
        self::PLATFORM_MACOS => 'macOS',
        self::PLATFORM_IOS => 'iOS',
        self::PLATFORM_ANDROID => 'Android',
    ];

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_GITHUB = 'github';

    public const MIN_ARTIFACT_BYTES = 1024;

    public const MAX_ARTIFACT_BYTES = 512 * 1024 * 1024;

    public const ALLOWED_ARTIFACT_EXTENSIONS = [
        'apk', 'dmg', 'exe', 'msi', 'pkg', 'zip',
    ];

    protected $fillable = [
        'platform', 'name', 'note', 'url', 'sort',
        'source_type', 'github_repo', 'asset_pattern',
        'local_path', 'synced_version', 'synced_at',
        'published_sha256', 'published_size', 'published_verified_at',
        'pending_path', 'pending_version', 'pending_sha256', 'pending_size',
        'pending_source_digest', 'pending_at',
        'last_sync_attempt_at', 'last_sync_error',
    ];

    protected $casts = [
        'synced_at' => 'datetime',
        'published_size' => 'integer',
        'published_verified_at' => 'datetime',
        'pending_size' => 'integer',
        'pending_at' => 'datetime',
        'last_sync_attempt_at' => 'datetime',
    ];

    public function hasPendingRelease(): bool
    {
        return $this->pending_path !== null
            && $this->pending_version !== null
            && $this->pending_sha256 !== null
            && $this->pending_size !== null;
    }
}
