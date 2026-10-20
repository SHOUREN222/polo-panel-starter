<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A 分站's pre-funded 进货余额 row. CentralConnection so it always reads/writes
 * the central DB, even when the current default connection is a tenant's — the
 * balance is a platform-level ledger, not per-tenant data.
 */
class TenantWholesale extends Model
{
    use CentralConnection;

    protected $table = 'tenant_wholesale';

    protected $fillable = ['tenant_id', 'balance'];

    protected $casts = ['balance' => 'integer'];
}
