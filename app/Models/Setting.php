<?php

namespace App\Models;

use App\Support\EncryptedValue;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class Setting extends Model
{
    // The Setting model itself is the platform (central) store. A 分站 overrides
    // only its BRANDING_KEYS via TenantSetting (tenant DB); everything else is
    // platform-shared and always read from central.
    use CentralConnection;

    protected $fillable = ['key', 'value'];

    /** Values that must never be stored as plaintext in either settings table. */
    public const SENSITIVE_KEYS = [
        'epay_key',
        'epay_pid',
        'mail_password',
        'mail_username',
        'node_comm_key',
        'onip_app_id',
        'onip_app_secret',
        'onip_callback_secret',
        'relay_backup_ssh_password',
        'relay_reality_private_key',
        'relay_ssh_password',
        'telegram_bot_token',
    ];

    /** Keys a 分站 may override for its own look; else it inherits the platform's. */
    public const BRANDING_KEYS = [
        'site_name', 'site_logo_url', 'site_description',
        'theme_primary_color', 'theme_font_size',
        'support_widget_enabled', 'support_telegram', 'support_telegram_group',
        'support_email', 'support_wechat', 'support_qq', 'support_note',
        'support_wechat_qr', 'support_qq_qr', 'tos_url',
        // The 讨论组 shown in 个人中心 — a 分站 needs its own, or its customers
        // end up in the platform's group alongside other shops' users.
        'telegram_group_url',
    ];

    /**
     * A 分站 may run its own Telegram bot so its customers talk to the agent's
     * brand instead of the platform's. The token/username are per-site config,
     * and the poll offset is per-BOT cursor state — sharing that one would make
     * two bots eat each other's updates.
     */
    public const TENANT_BOT_KEYS = [
        'telegram_bot_token', 'telegram_bot_username', 'telegram_poll_offset',
    ];

    private static function tenantOverridable(string $key): bool
    {
        return tenant() && (
            in_array($key, self::BRANDING_KEYS, true)
            || in_array($key, self::TENANT_BOT_KEYS, true)
        );
    }

    /**
     * Ways for a customer to reach "us". Unlike cosmetic branding, these must
     * NOT fall back to the platform's value inside a 分站: an agent who hasn't
     * filled in support details would otherwise be sending their own customers
     * to the platform's inbox / Telegram group — revealing the reseller
     * arrangement, and dropping them among another shop's users. Better to show
     * nothing than to point them at the wrong company.
     */
    public const TENANT_CONTACT_KEYS = [
        'telegram_group_url',
        'support_telegram', 'support_telegram_group', 'support_email',
        'support_wechat', 'support_qq', 'support_wechat_qr', 'support_qq_qr',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::tenantOverridable($key)) {
            $override = TenantSetting::where('key', $key)->value('value');
            if ($override !== null) {
                return self::reveal($key, $override);
            }

            // Contact routes and bot config must not inherit. For contacts it
            // would misdirect the agent's customers; for the bot it's worse —
            // every 分站 would look like it had one, so the platform bot would
            // stop covering them AND each would poll the platform's own token
            // with a separate cursor, stealing each other's updates.
            if (in_array($key, self::TENANT_CONTACT_KEYS, true)
                || in_array($key, self::TENANT_BOT_KEYS, true)) {
                return $default;
            }
        }

        // central (CentralConnection) — platform / main-site value
        $value = static::where('key', $key)->value('value');

        return $value === null ? $default : self::reveal($key, $value);
    }

    public static function set(string $key, mixed $value): void
    {
        if (self::tenantOverridable($key)) {
            TenantSetting::updateOrCreate(['key' => $key], ['value' => self::protect($key, $value)]);

            return;
        }

        static::updateOrCreate(['key' => $key], ['value' => self::protect($key, $value)]);
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key, null); // reuse get() so 分站 overrides apply

        return $value === null ? $default : (bool) $value;
    }

    public static function isSensitive(string $key): bool
    {
        return in_array($key, self::SENSITIVE_KEYS, true);
    }

    public static function protect(string $key, mixed $value): mixed
    {
        if (! self::isSensitive($key) || $value === null) {
            return $value;
        }

        return EncryptedValue::encrypt((string) $value);
    }

    private static function reveal(string $key, mixed $value): mixed
    {
        if (! self::isSensitive($key) || ! is_string($value)) {
            return $value;
        }

        return EncryptedValue::decrypt($value);
    }
}
