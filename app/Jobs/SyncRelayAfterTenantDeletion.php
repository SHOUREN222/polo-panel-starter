<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\NodeRelayService;
use Illuminate\Support\Facades\Log;

/** Remove a deleted tenant's users and onip routes from the shared relay. */
class SyncRelayAfterTenantDeletion
{
    public function __construct(private readonly Tenant $tenant) {}

    public function handle(NodeRelayService $relay): void
    {
        try {
            $relay->sync();
        } catch (\Throwable $e) {
            // The tenant and its database are already gone at this point. Keep
            // deletion successful and leave an actionable retry signal in logs.
            Log::warning('relay sync after tenant deletion failed', [
                'tenant_id' => $this->tenant->getTenantKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
