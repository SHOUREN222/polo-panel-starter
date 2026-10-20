<?php

namespace App\Services;

use App\Models\GiftCard;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GiftCardService
{
    public function redeem(User $user, string $code): GiftCard
    {
        return DB::transaction(function () use ($user, $code) {
            $card = GiftCard::where('code', $code)->lockForUpdate()->first();

            if (! $card) {
                throw ValidationException::withMessages(['code' => '礼品卡兑换码不存在']);
            }

            if ($card->redeemed) {
                throw ValidationException::withMessages(['code' => '这张礼品卡已经被兑换过了']);
            }

            $card->update([
                'redeemed' => true,
                'redeemed_by_user_id' => $user->id,
                'redeemed_at' => now(),
            ]);

            $user->changeBalance($card->amount, \App\Models\BalanceTransaction::TYPE_GIFT_CARD, '礼品卡 '.$card->code);

            return $card->fresh();
        });
    }
}
