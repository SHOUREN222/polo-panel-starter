<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    public const TYPE_FIXED = 'fixed';
    public const TYPE_PERCENT = 'percent';

    protected $fillable = ['code', 'type', 'value', 'max_uses', 'per_user_limit', 'used_count', 'expires_at', 'enabled'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    public function isValid(): bool
    {
        if (! $this->enabled) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return false;
        }

        return true;
    }

    /**
     * Discount in cents for a given price, clamped so it never exceeds the
     * price itself (a 100%-off or oversized fixed coupon can't make an
     * order negative).
     */
    public function discountFor(int $priceCents): int
    {
        $discount = $this->type === self::TYPE_PERCENT
            ? (int) round($priceCents * $this->value / 100)
            : $this->value;

        return min($discount, $priceCents);
    }
}
