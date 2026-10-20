<?php

namespace App\Support;

use App\Models\User;

/** Security state proving that an admin used the dedicated login flow. */
class AdminSession
{
    private const PENDING_KEY = 'admin_2fa.pending';

    private const VERIFIED_KEY = 'admin_auth.verified';

    public static function beginTwoFactor(User $user): void
    {
        session()->regenerate();
        session()->forget(self::VERIFIED_KEY);
        session()->put(self::PENDING_KEY, [
            'user_id' => (string) $user->getKey(),
            'site' => self::siteId(),
            'issued_at' => now()->timestamp,
            // Invalidates the pending challenge if the password is reset while
            // it is open, without storing the password hash itself in session.
            'password_fingerprint' => hash('sha256', (string) $user->getAuthPassword()),
        ]);
    }

    public static function pendingUser(): ?User
    {
        $pending = session(self::PENDING_KEY);
        if (! is_array($pending)
            || ($pending['site'] ?? null) !== self::siteId()
            || ! isset($pending['user_id'], $pending['issued_at'], $pending['password_fingerprint'])) {
            self::clearPending();

            return null;
        }

        $age = now()->timestamp - (int) $pending['issued_at'];
        if ($age < 0 || $age > (int) config('admin.two_factor_pending_ttl', 300)) {
            self::clearPending();

            return null;
        }

        $user = User::find($pending['user_id']);
        if (! $user || $user->banned || ! $user->isAdmin() || ! $user->hasTwoFactorEnabled()) {
            self::clearPending();

            return null;
        }

        $fingerprint = hash('sha256', (string) $user->getAuthPassword());
        if (! hash_equals((string) $pending['password_fingerprint'], $fingerprint)) {
            self::clearPending();

            return null;
        }

        return $user;
    }

    public static function clearPending(): void
    {
        session()->forget(self::PENDING_KEY);
    }

    public static function markVerified(User $user, bool $twoFactorPassed): void
    {
        $now = now()->timestamp;
        session()->put(self::VERIFIED_KEY, [
            'user_id' => (string) $user->getKey(),
            'site' => self::siteId(),
            'verified_at' => $now,
            'two_factor_at' => $twoFactorPassed ? $now : null,
        ]);
    }

    /** The setup confirmation itself is a valid second-factor proof. */
    public static function markTwoFactorVerified(User $user): void
    {
        $verified = session(self::VERIFIED_KEY, []);
        if (! is_array($verified)
            || ($verified['user_id'] ?? null) !== (string) $user->getKey()
            || ($verified['site'] ?? null) !== self::siteId()) {
            return;
        }

        $verified['two_factor_at'] = now()->timestamp;
        session()->put(self::VERIFIED_KEY, $verified);
    }

    public static function isVerified(User $user): bool
    {
        $verified = session(self::VERIFIED_KEY);
        if (! is_array($verified)
            || ($verified['user_id'] ?? null) !== (string) $user->getKey()
            || ($verified['site'] ?? null) !== self::siteId()) {
            return false;
        }

        $maxAge = (int) config('admin.verified_session_ttl', 43200);
        $verifiedAge = now()->timestamp - (int) ($verified['verified_at'] ?? 0);
        if ($verifiedAge < 0 || $verifiedAge > $maxAge) {
            return false;
        }

        if ($user->hasTwoFactorEnabled()) {
            $twoFactorAge = now()->timestamp - (int) ($verified['two_factor_at'] ?? 0);

            return $twoFactorAge >= 0 && $twoFactorAge <= $maxAge;
        }

        return true;
    }

    public static function isRecentlyVerified(User $user): bool
    {
        if (! self::isVerified($user)) {
            return false;
        }

        $verified = session(self::VERIFIED_KEY);
        $proofAt = $user->hasTwoFactorEnabled()
            ? (int) ($verified['two_factor_at'] ?? 0)
            : (int) ($verified['verified_at'] ?? 0);
        $age = now()->timestamp - $proofAt;

        return $age >= 0 && $age <= (int) config('admin.sensitive_session_ttl', 900);
    }

    private static function siteId(): string
    {
        return tenant() ? 'tenant:'.tenant()->getTenantKey() : 'central';
    }
}
