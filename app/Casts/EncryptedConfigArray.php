<?php

namespace App\Casts;

use App\Support\EncryptedValue;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonException;

/**
 * Keeps configuration JSON queryable while encrypting credential fields.
 * Plaintext legacy rows remain readable until the migration command rewrites
 * them, so deploying the cast and migrating existing data is interruption-free.
 */
class EncryptedConfigArray implements CastsAttributes
{
    private const SENSITIVE_KEYS = [
        'api_key',
        'client_secret',
        'ipn_secret',
        'key',
        'password',
        'private_key',
        'secret',
        'token',
        'webhook_secret',
    ];

    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $this->transform($decoded, false) : null;
    }

    /** @throws JsonException */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException("{$key} must be an array or JSON object.");
        }

        return json_encode(
            $this->transform($value, true),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    public static function needsEncryption(?string $rawJson): bool
    {
        if ($rawJson === null || $rawJson === '') {
            return false;
        }

        $decoded = json_decode($rawJson, true);

        return is_array($decoded) && self::containsPlaintextSecret($decoded);
    }

    private function transform(array $values, bool $encrypt): array
    {
        foreach ($values as $field => $value) {
            if (is_array($value)) {
                $values[$field] = $this->transform($value, $encrypt);

                continue;
            }

            if (! is_string($field) || ! self::isSensitiveKey($field) || $value === null) {
                continue;
            }

            $values[$field] = $encrypt
                ? EncryptedValue::encrypt((string) $value)
                : EncryptedValue::decrypt((string) $value);
        }

        return $values;
    }

    private static function containsPlaintextSecret(array $values): bool
    {
        foreach ($values as $field => $value) {
            if (is_array($value) && self::containsPlaintextSecret($value)) {
                return true;
            }

            if (is_string($field)
                && self::isSensitiveKey($field)
                && $value !== null
                && $value !== ''
                && ! EncryptedValue::isEncrypted($value)) {
                return true;
            }
        }

        return false;
    }

    private static function isSensitiveKey(string $key): bool
    {
        return in_array(strtolower($key), self::SENSITIVE_KEYS, true);
    }
}
