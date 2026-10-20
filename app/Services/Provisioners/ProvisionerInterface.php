<?php

namespace App\Services\Provisioners;

use App\Models\ProvisionTask;
use App\Models\SubscriptionInstance;

interface ProvisionerInterface
{
    /**
     * Actually go provision the resource (assign an IP, configure a node,
     * call a supplier API, etc.) described by the task's payload.
     *
     * Must throw on failure; the caller records the failure on the task.
     *
     * @return array<string, mixed> Result data to store on the task/instance
     *                              (e.g. ['ip' => '1.2.3.4', 'provider' => '...']).
     */
    public function provision(ProvisionTask $task): array;

    /**
     * Extend an already-provisioned resource's term in place — no new IP,
     * nothing else changes — for a renewal that lands before the current
     * term lapses (see ProvisionService::renewInstance()). Must throw on
     * failure: a renewal that can't actually be extended upstream must not
     * be allowed to succeed locally either, or the customer's paid-for term
     * and the supplier's actual lease silently drift apart.
     */
    public function renewExisting(SubscriptionInstance $instance, string $period): void;
}
