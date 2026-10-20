<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class InviteCode extends Model
{
    protected $fillable = ['user_id', 'code'];

    protected function casts(): array
    {
        return ['used_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (InviteCode $inviteCode) {
            $inviteCode->code ??= strtoupper(Str::random(8));
        });
    }

    /**
     * When 邀请码永不失效 is off, a code becomes unusable after its first
     * successful registration — checked in the registration flow, set here
     * (not mass-assigned; used_at is deliberately excluded from $fillable).
     */
    public function isUsable(): bool
    {
        if (Setting::getBool('invite_code_never_expires', true)) {
            return true;
        }

        return $this->used_at === null;
    }

    public function markUsed(): void
    {
        $this->used_at = now();
        $this->save();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Users who registered specifically through this code — distinct from
     * User::referrals(), which is everyone referred by this code's owner
     * regardless of which of their codes was used.
     */
    public function registeredUsers(): HasMany
    {
        return $this->hasMany(User::class, 'invite_code_id');
    }
}
