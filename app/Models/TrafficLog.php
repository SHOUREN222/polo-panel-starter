<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrafficLog extends Model
{
    protected $fillable = [
        'user_id', 'subscription_instance_id', 'server_id',
        'date', 'upload_bytes', 'download_bytes', 'rate',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'rate' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscriptionInstance(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInstance::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function totalBytes(): int
    {
        return (int) round(($this->upload_bytes + $this->download_bytes) * $this->rate);
    }
}
