<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Self-contained TOTP (RFC 6238) + HOTP (RFC 4226) so admin 2FA needs no
 * external package — it's all HMAC over PHP's built-in hash_hmac, which is
 * the standard construction, not home-rolled crypto. Verified in tests
 * against the official RFC 6238 test vectors.
 *
 * Compatible with Google Authenticator / Authy / 1Password etc.: SHA1,
 * 6 digits, 30-second period, Base32 secret.
 */
class TwoFactorService
{
    private const DIGITS = 6;

    private const PERIOD = 30;

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A fresh 160-bit Base32 secret (the size Google Authenticator expects). */
    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    /** The otpauth:// URI an authenticator app turns into a QR / manual entry. */
    public function otpauthUri(User $user, string $secret): string
    {
        $issuer = rawurlencode(Setting::get('site_name', config('app.name')) ?: 'Panel');
        $label = rawurlencode($issuer.':'.$user->email);

        return "otpauth://totp/{$label}?"
            .http_build_query([
                'secret' => $secret,
                'issuer' => Setting::get('site_name', config('app.name')) ?: 'Panel',
                'algorithm' => 'SHA1',
                'digits' => self::DIGITS,
                'period' => self::PERIOD,
            ]);
    }

    /**
     * True when $code is valid for $secret right now. A ±1 step window absorbs
     * clock drift between the server and the phone (so ~90s of tolerance).
     */
    public function verify(string $secret, string $code, ?int $atTimestamp = null): bool
    {
        $code = trim($code);
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $timestamp = $atTimestamp ?? time();
        $counter = intdiv($timestamp, self::PERIOD);

        foreach ([-1, 0, 1] as $offset) {
            if (hash_equals($this->hotp($secret, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    /** One HOTP value (RFC 4226) for a given counter — the TOTP building block. */
    public function hotp(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);
        $binCounter = pack('N*', 0).pack('N*', $counter); // 64-bit big-endian
        $hash = hash_hmac('sha1', $binCounter, $key, true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $truncated = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        );

        return str_pad((string) ($truncated % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** A fresh batch of one-time recovery codes (for a lost authenticator). */
    public function generateRecoveryCodes(int $count = 8): array
    {
        return collect(range(1, $count))
            ->map(fn () => Str::upper(Str::random(5)).'-'.Str::upper(Str::random(5)))
            ->all();
    }

    public function base32Encode(string $binary): string
    {
        $bits = '';
        foreach (str_split($binary) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $result = '';
        foreach (str_split($bits, 5) as $chunk) {
            $result .= self::BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $result;
    }

    public function base32Decode(string $base32): string
    {
        $base32 = rtrim(strtoupper($base32), '=');
        $bits = '';
        foreach (str_split($base32) as $char) {
            $index = strpos(self::BASE32_ALPHABET, $char);
            if ($index === false) {
                continue; // ignore spaces / stray formatting chars
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $binary = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $binary .= chr(bindec($byte));
            }
        }

        return $binary;
    }
}
