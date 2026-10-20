<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/** Central, idempotent wholesale debit for one tenant order. */
class TenantWholesaleSettlement extends Model
{
    use CentralConnection;

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_RELEASED = 'released';

    protected $fillable = [
        'tenant_id', 'order_id', 'trade_no', 'amount', 'status',
        'reserved_at', 'completed_at', 'released_at',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'amount' => 'integer',
        'reserved_at' => 'datetime',
        'completed_at' => 'datetime',
        'released_at' => 'datetime',
    ];
}
