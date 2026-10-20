<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';

    public const TYPE_NEW = 'new';
    public const TYPE_RENEWAL = 'renewal';
    public const TYPE_CHANGE = 'change';
    public const TYPE_TRAFFIC_PACK = 'traffic_pack';
    // Cross-type upgrade: buy a dedicated-ISP line while stopping the user's
    // active shared plan and crediting its unused days toward the ISP price.
    public const TYPE_UPGRADE = 'upgrade';

    protected $fillable = [
        'user_id', 'paid_by_agent_id', 'plan_id', 'renew_subscription_instance_id', 'payment_id',
        'coupon_id', 'type', 'period', 'trade_no', 'total_amount', 'discount_amount',
        'balance_amount', 'balance_credit_amount', 'status', 'paid_at', 'selected_resource_profile', 'pack_kind', 'pack_bytes',
    ];

    protected $casts = [
        'paid_at' => 'datetime',
        'selected_resource_profile' => 'array',
        'total_amount' => 'integer',
        'discount_amount' => 'integer',
        'balance_amount' => 'integer',
        'balance_credit_amount' => 'integer',
    ];

    /**
     * What a payment gateway still needs to charge: the order total minus
     * whatever has already been covered from the user's account balance.
     * Zero means balance covered the whole order and no gateway is needed.
     */
    public function payableAmount(): int
    {
        return max(0, $this->total_amount - $this->balance_amount);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function provisionTask(): HasOne
    {
        return $this->hasOne(ProvisionTask::class);
    }

    public function renewInstance(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInstance::class, 'renew_subscription_instance_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isRenewal(): bool
    {
        return $this->type === self::TYPE_RENEWAL;
    }

    public function isUpgrade(): bool
    {
        return $this->type === self::TYPE_UPGRADE;
    }

    /**
     * Short human label for the order type, for order lists in both the
     * customer and admin UIs.
     */
    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_RENEWAL => '续费',
            self::TYPE_UPGRADE => '升级',
            self::TYPE_CHANGE => '变更',
            self::TYPE_TRAFFIC_PACK => '流量包',
            default => '新购',
        };
    }
}
