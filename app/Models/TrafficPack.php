<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrafficPack extends Model
{
    public const KIND_ONE_TIME = 'one_time';
    public const KIND_RESETTABLE = 'resettable';

    protected $fillable = ['user_id', 'plan_id', 'subscription_instance_id', 'order_id', 'kind', 'bytes'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscriptionInstance(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInstance::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
