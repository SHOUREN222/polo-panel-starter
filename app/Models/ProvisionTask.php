<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProvisionTask extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'order_id', 'subscription_instance_id', 'status',
        'payload', 'result', 'error', 'attempts',
    ];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function subscriptionInstance(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInstance::class);
    }
}
