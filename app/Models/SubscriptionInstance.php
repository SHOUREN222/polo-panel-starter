<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SubscriptionInstance extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    // Auto-suspended for exceeding the traffic quota — distinct from
    // admin DISABLED so enforcement can revive it once a pack/renewal
    // puts the line back under quota, without touching admin-disabled ones.
    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'user_id', 'plan_id', 'created_order_id', 'last_order_id',
        'token', 'uuid', 'transfer_enable', 'u', 'd', 'status',
        'expired_at', 'meta', 'expire_reminder_sent_at', 'traffic_reminder_sent_at',
        'last_traffic_reset_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'expired_at' => 'datetime',
        'expire_reminder_sent_at' => 'date',
        'traffic_reminder_sent_at' => 'date',
        'last_traffic_reset_at' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (SubscriptionInstance $instance) {
            $instance->uuid ??= (string) Str::uuid();
            $instance->token ??= Str::random(48);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isExpired(): bool
    {
        return $this->expired_at !== null && $this->expired_at->isPast();
    }

    /**
     * This line's total quota = the provisioned base plus any traffic packs
     * bought for this specific instance (dedicated-ISP packs are scoped per
     * line, unlike shared-pool packs). All usage/remaining math goes through
     * here so packs are reflected everywhere consistently.
     */
    public function effectiveQuotaBytes(): int
    {
        return (int) $this->transfer_enable
            + (int) TrafficPack::where('subscription_instance_id', $this->id)->sum('bytes');
    }

    public function getRemainingTraffic(): int
    {
        return max(0, $this->effectiveQuotaBytes() - ((int) $this->u + (int) $this->d));
    }

    public function usageRatio(): float
    {
        $quota = $this->effectiveQuotaBytes();
        if ($quota <= 0) {
            return 0.0;
        }

        return ((int) $this->u + (int) $this->d) / $quota;
    }

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_ACTIVE && ! $this->isExpired();
    }
}
