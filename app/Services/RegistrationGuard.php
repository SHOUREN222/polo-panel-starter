<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Anti-abuse checks for the public registration form — each one is a
 * separate admin-configurable toggle under 系统设置 → 安全设置, mirroring
 * Xboard's own set. Kept as small independent methods rather than one big
 * validate() so each check's failure message stays specific.
 */
class RegistrationGuard
{
    public function ipLimitExceeded(string $ip): bool
    {
        if (! Setting::getBool('ip_register_limit_enabled', false)) {
            return false;
        }

        $max = (int) Setting::get('ip_register_max_attempts', 5);

        return RateLimiter::tooManyAttempts($this->ipKey($ip), $max);
    }

    public function recordRegistration(string $ip): void
    {
        if (! Setting::getBool('ip_register_limit_enabled', false)) {
            return;
        }

        $minutes = (int) Setting::get('ip_register_lockout_minutes', 60);
        RateLimiter::hit($this->ipKey($ip), $minutes * 60);
    }

    private function ipKey(string $ip): string
    {
        return \App\Support\SiteKey::scope('register:ip:'.$ip);
    }

    public function emailAllowedByWhitelist(string $email): bool
    {
        if (! Setting::getBool('email_whitelist_enabled', false)) {
            return true;
        }

        $domain = strtolower(substr(strrchr($email, '@'), 1));
        $allowed = array_filter(array_map('trim', explode(',', strtolower(Setting::get('email_whitelist', '')))));

        return in_array($domain, $allowed, true);
    }

    public function isBlockedGmailAlias(string $email): bool
    {
        if (! Setting::getBool('block_gmail_alias', false)) {
            return false;
        }

        $normalized = $this->normalizeGmail($email);
        if ($normalized === null) {
            return false;
        }

        return User::query()
            ->get(['email'])
            ->contains(fn (User $user) => $this->normalizeGmail($user->email) === $normalized);
    }

    /**
     * gmail.com/googlemail.com ignore dots in the local part and treat
     * everything after a "+" as a tag — someone+a@gmail.com and
     * some.one@gmail.com both deliver to the same inbox. Returns null for
     * non-Gmail addresses (nothing to normalize).
     */
    private function normalizeGmail(string $email): ?string
    {
        $email = strtolower($email);
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        if (! in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            return null;
        }

        $local = strstr($local, '+', true) ?: $local;
        $local = str_replace('.', '', $local);

        return $local.'@gmail.com';
    }

    public function verifyCaptcha(?int $expected, ?string $answer): bool
    {
        if (! Setting::getBool('captcha_enabled', false)) {
            return true;
        }

        return $expected !== null && $answer !== null && (int) $answer === $expected;
    }
}
