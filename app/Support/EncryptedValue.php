<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;

final class EncryptedValue
{
    public const PREFIX = 'encrypted:v1:';

    public static function encrypt(?string $value): ?string
    {
        if ($value === null || self::isEncrypted($value)) {
            return $value;
        }

        return self::PREFIX.Crypt::encryptString($value);
    }

    public static function decrypt(?string $value): ?string
    {
        if ($value === null || ! self::isEncrypted($value)) {
            return $value;
        }

        return Crypt::decryptString(substr($value, strlen(self::PREFIX)));
    }

    public static function isEncrypted(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }
}
