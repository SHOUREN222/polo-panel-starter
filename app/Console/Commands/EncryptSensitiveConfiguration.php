<?php

namespace App\Console\Commands;

use App\Casts\EncryptedConfigArray;
use App\Models\Payment;
use App\Models\Server;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Support\EncryptedValue;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

#[Signature('security:encrypt-sensitive-config')]
#[Description('Encrypt plaintext credentials in central and tenant configuration stores')]
class EncryptSensitiveConfiguration extends Command
{
    public function handle(): int
    {
        $counts = [
            'central_settings' => $this->encryptSettings(Setting::query()),
            'central_payments' => $this->encryptConfigs(Payment::query()),
            'server_configs' => $this->encryptConfigs(Server::query()),
            'server_heartbeat_secrets' => $this->encryptHeartbeatSecrets(),
            'tenant_settings' => 0,
            'tenant_payments' => 0,
        ];

        foreach (Tenant::all() as $tenant) {
            $tenant->run(function () use (&$counts) {
                $counts['tenant_settings'] += $this->encryptSettings(TenantSetting::query());
                $counts['tenant_payments'] += $this->encryptConfigs(Payment::query());
            });
        }

        $this->table(['Store', 'Encrypted rows'], collect($counts)->map(
            fn (int $count, string $store) => [$store, $count]
        )->values()->all());

        return self::SUCCESS;
    }

    private function encryptSettings(Builder $query): int
    {
        $count = 0;

        $query->orderBy('id')->each(function (Model $setting) use (&$count) {
            $key = (string) $setting->getAttribute('key');
            $raw = $setting->getRawOriginal('value');

            if (! Setting::isSensitive($key) || $raw === null || EncryptedValue::isEncrypted($raw)) {
                return;
            }

            $setting->forceFill(['value' => Setting::protect($key, $raw)])->saveQuietly();
            $count++;
        });

        return $count;
    }

    private function encryptHeartbeatSecrets(): int
    {
        $count = 0;

        Server::query()->whereNotNull('heartbeat_secret')->orderBy('id')->each(
            function (Server $server) use (&$count) {
                $raw = $server->getRawOriginal('heartbeat_secret');
                $secret = EncryptedValue::decrypt($raw);
                $expectedHash = hash('sha256', (string) $secret);
                $currentHash = $server->getRawOriginal('heartbeat_secret_hash');

                if (EncryptedValue::isEncrypted($raw)
                    && is_string($currentHash)
                    && hash_equals($expectedHash, $currentHash)) {
                    return;
                }

                DB::connection($server->getConnectionName())
                    ->table($server->getTable())
                    ->where('id', $server->getKey())
                    ->update([
                        'heartbeat_secret' => EncryptedValue::encrypt($secret),
                        'heartbeat_secret_hash' => $expectedHash,
                    ]);
                $count++;
            },
        );

        return $count;
    }

    private function encryptConfigs(Builder $query): int
    {
        $count = 0;

        $query->orderBy('id')->each(function (Model $model) use (&$count) {
            $raw = $model->getRawOriginal('config');

            if (! EncryptedConfigArray::needsEncryption($raw)) {
                return;
            }

            $model->setAttribute('config', $model->getAttribute('config'));
            $model->saveQuietly();
            $count++;
        });

        return $count;
    }
}
