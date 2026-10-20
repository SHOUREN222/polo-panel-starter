<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * One 站长-issued credit to a 分站's 进货余额 — the credit-side counterpart
 * to TenantWholesaleSettlement (which only logs the debit side, per order).
 * CentralConnection for the same reason as TenantWholesale: this is the
 * platform's ledger of the tenant, not the tenant's own data.
 */
class TenantWholesaleTopup extends Model
{
    use CentralConnection;

    protected $fillable = ['tenant_id', 'amount', 'cash_collected'];

    protected $casts = [
        'amount' => 'integer',
        'cash_collected' => 'integer',
    ];
}
