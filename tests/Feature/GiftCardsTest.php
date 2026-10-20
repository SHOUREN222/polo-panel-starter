<?php

namespace Tests\Feature;

use App\Models\GiftCard;
use App\Models\User;
use App\Services\GiftCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class GiftCardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_generate_gift_cards(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        \Livewire\Livewire::actingAs($admin)->test('admin.giftcards')
            ->call('create')
            ->set('amount', '50')
            ->set('quantity', 3)
            ->call('generate');

        $this->assertSame(3, GiftCard::count());
        $this->assertSame(5000, GiftCard::first()->amount);
    }

    public function test_redeeming_a_gift_card_adds_to_balance(): void
    {
        $user = User::factory()->create(['balance' => 0]);
        $card = GiftCard::create(['code' => 'ABCD-1234-EFGH', 'amount' => 2000]);

        app(GiftCardService::class)->redeem($user, 'ABCD-1234-EFGH');

        $this->assertSame(2000, $user->fresh()->balance);
        $this->assertTrue($card->fresh()->redeemed);
        $this->assertSame($user->id, $card->fresh()->redeemed_by_user_id);
    }

    public function test_gift_card_cannot_be_redeemed_twice(): void
    {
        $user = User::factory()->create();
        GiftCard::create(['code' => 'ONETIME', 'amount' => 1000, 'redeemed' => true, 'redeemed_by_user_id' => $user->id, 'redeemed_at' => now()]);

        $this->expectException(ValidationException::class);
        app(GiftCardService::class)->redeem($user, 'ONETIME');
    }

    public function test_invalid_gift_card_code_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->expectException(ValidationException::class);
        app(GiftCardService::class)->redeem($user, 'NOPE');
    }

    public function test_customer_can_redeem_via_orders_page(): void
    {
        $user = User::factory()->create(['balance' => 0]);
        GiftCard::create(['code' => 'WEBTEST', 'amount' => 500]);

        \Livewire\Livewire::actingAs($user)->test('plans')
            ->set('giftCardCode', 'webtest')
            ->call('redeemGiftCard');

        $this->assertSame(500, $user->fresh()->balance);
    }
}
