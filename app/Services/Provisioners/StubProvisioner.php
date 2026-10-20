<?php

namespace App\Services\Provisioners;

use App\Models\ProvisionTask;
use App\Models\SubscriptionInstance;

/**
 * Local-development stand-in for the real provisioning backend (the
 * existing `resource-provision-worker-real` Python worker, or whatever
 * replaces it). Fakes an IP assignment so the rest of the system can be
 * built and tested without touching real infrastructure.
 */
class StubProvisioner implements ProvisionerInterface
{
    public function provision(ProvisionTask $task): array
    {
        $profile = $task->payload['resource_profile'] ?? [];

        return [
            'ip' => $this->fakeIp(),
            'port' => 443,
            'protocol' => 'shadowsocks',
            'method' => 'aes-256-gcm',
            'provider' => $profile['provider'] ?? 'stub',
            'country' => $profile['country'] ?? null,
            'city' => $profile['city'] ?? null,
            'provisioned_at' => now()->toIso8601String(),
        ];
    }

    private function fakeIp(): string
    {
        return implode('.', [203, 0, 113, random_int(1, 254)]);
    }

    public function renewExisting(SubscriptionInstance $instance, string $period): void
    {
        // Nothing upstream to extend.
    }
}
