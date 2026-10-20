<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Commission extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';

    // Admin-voided (fraud / correction) — never confirms, never transfers.
    public const STATUS_VOID = 'void';

    protected $fillable = [
        'user_id', 'referred_user_id', 'order_id', 'amount',
        'status', 'confirmed_at', 'transferred_at',
    ];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'transferred_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function referredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function isTransferred(): bool
    {
        return $this->transferred_at !== null;
    }

    public function isVoid(): bool
    {
        return $this->status === self::STATUS_VOID;
    }
}
