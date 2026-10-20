<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GiftCard extends Model
{
    protected $fillable = ['code', 'amount', 'redeemed', 'redeemed_by_user_id', 'redeemed_at', 'created_by_agent_id', 'agent_cost'];

    protected function casts(): array
    {
        return [
            'redeemed' => 'boolean',
            'redeemed_at' => 'datetime',
            'agent_cost' => 'integer',
        ];
    }

    public function redeemedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redeemed_by_user_id');
    }

    public function createdByAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_agent_id');
    }
}
