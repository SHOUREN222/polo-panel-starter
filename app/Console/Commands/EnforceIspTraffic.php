<?php

namespace App\Console\Commands;

use App\Models\SubscriptionInstance;
use App\Services\NodeRelayService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('isp:enforce-traffic')]
#[Description('Suspend dedicated-ISP lines that have exceeded their traffic quota (and revive ones back under quota after a pack/renewal), then re-push the relay config so the node actually cuts them off.')]
class EnforceIspTraffic extends Command
{
    public function handle(NodeRelayService $relay): int
    {
        $changed = false;

        // Subscriptions live in each site's own database, and the scheduler
        // runs in central context — so without sweeping the 分站 too, their
        // customers would never be cut off for going over quota while the
        // platform keeps paying for the relay bandwidth.
        \App\Support\EachSite::run(function (?\App\Models\Tenant $tenant) use (&$changed) {
            $label = $tenant ? "[{$tenant->getTenantKey()}] " : '';

            // Both active (candidates to suspend) and already-suspended (candidates
            // to revive). Only onip relay lines — that's what the relay enforces.
            $instances = SubscriptionInstance::whereIn('status', [
                SubscriptionInstance::STATUS_ACTIVE,
                SubscriptionInstance::STATUS_SUSPENDED,
            ])->get()->filter(fn (SubscriptionInstance $i) => ($i->meta['provider'] ?? null) === 'onip');

            foreach ($instances as $instance) {
                $quota = $instance->effectiveQuotaBytes();
                if ($quota <= 0) {
                    continue;
                }

                $over = ((int) $instance->u + (int) $instance->d) >= $quota;

                if ($over && $instance->status === SubscriptionInstance::STATUS_ACTIVE) {
                    $instance->update(['status' => SubscriptionInstance::STATUS_SUSPENDED]);
                    $changed = true;
                    $this->info("{$label}suspended #{$instance->id} (used ".($instance->u + $instance->d)." / {$quota})");
                } elseif (! $over && $instance->status === SubscriptionInstance::STATUS_SUSPENDED) {
                    $instance->update(['status' => SubscriptionInstance::STATUS_ACTIVE]);
                    $changed = true;
                    $this->info("{$label}revived #{$instance->id} (used ".($instance->u + $instance->d)." / {$quota})");
                }
            }
        });

        // Every sync() restarts xray-relay, so only re-push when membership
        // actually changed — no-op runs (the common case) must not touch it.
        if ($changed) {
            $relay->sync();
        }

        return self::SUCCESS;
    }
}
