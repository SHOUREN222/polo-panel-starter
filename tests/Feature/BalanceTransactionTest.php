<?php

namespace Tests\Feature;

use App\Models\BalanceTransaction;
use App\Models\GiftCard;
use App\Models\User;
use App\Services\GiftCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BalanceTransactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_change_balance_records_a_transaction_and_never_goes_negative(): void
    {
        $user = User::factory()->create(['balance' => 1000]);

        $applied = $user->changeBalance(-3000, BalanceTransaction::TYPE_ADJUST_ADMIN, '扣爆测试');

        $this->assertSame(-1000, $applied);       // capped: only ¥10 removed
        $this->assertSame(0, $user->fresh()->balance);
        $tx = $user->balanceTransactions()->first();
        $this->assertSame(-1000, $tx->amount);
        $this->assertSame(0, $tx->balance_after);
        $this->assertSame('adjust_admin', $tx->type);
    }

    public function test_gift_card_redeem_is_logged(): void
    {
        $user = User::factory()->create(['balance' => 0]);
        GiftCard::create(['code' => 'GC50', 'amount' => 5000]);

        app(GiftCardService::class)->redeem($user, 'GC50');

        $tx = $user->balanceTransactions()->first();
        $this->assertSame(5000, $tx->amount);
        $this->assertSame(BalanceTransaction::TYPE_GIFT_CARD, $tx->type);
        $this->assertStringContainsString('GC50', $tx->note);
    }

    public function test_admin_adjust_logs_who_did_it(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create(['balance' => 0]);

        Livewire::actingAs($admin)->test('admin.users')
            ->call('startAdjust', $user->id)
            ->set('adjustAmount', '20')->set('adjustNote', '客服补偿')
            ->call('saveAdjust');

        $tx = $user->balanceTransactions()->first();
        $this->assertSame(2000, $tx->amount);
        $this->assertSame($admin->id, $tx->admin_id);
        $this->assertSame('客服补偿', $tx->note);
    }
}
