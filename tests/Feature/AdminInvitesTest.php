<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminInvitesTest extends TestCase
{
    use RefreshDatabase;

    private function commission(User $referrer, User $referred, int $amount, string $status, bool $transferred = false): Commission
    {
        $plan = Plan::create(['name' => 'x', 'type' => Plan::TYPE_SHARED, 'group_id' => 1, 'transfer_enable' => 1024, 'prices' => ['month' => 1500], 'sell' => true]);
        $order = Order::create([
            'user_id' => $referred->id, 'plan_id' => $plan->id, 'type' => Order::TYPE_NEW,
            'period' => 'month', 'trade_no' => uniqid('t'), 'total_amount' => 1500, 'status' => Order::STATUS_PAID,
        ]);

        return Commission::create([
            'user_id' => $referrer->id, 'referred_user_id' => $referred->id, 'order_id' => $order->id,
            'amount' => $amount, 'status' => $status,
            'confirmed_at' => $status === Commission::STATUS_CONFIRMED ? now() : null,
            'transferred_at' => $transferred ? now() : null,
        ]);
    }

    public function test_deleting_a_user_cascades_away_their_commissions(): void
    {
        // The FKs cascade, so a deleted user leaves no orphaned commission to
        // crash the page — documents why the null-safety is just belt-and-suspenders.
        $referrer = User::factory()->create();
        $referred = User::factory()->create();
        $this->commission($referrer, $referred, 1000, Commission::STATUS_CONFIRMED);

        $referred->delete();

        $this->assertSame(0, Commission::count());
    }

    public function test_commission_summary_totals_by_status(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $r = User::factory()->create();
        $this->commission($r, User::factory()->create(), 1000, Commission::STATUS_PENDING);
        $this->commission($r, User::factory()->create(), 2000, Commission::STATUS_CONFIRMED);
        $this->commission($r, User::factory()->create(), 500, Commission::STATUS_CONFIRMED, transferred: true);

        Livewire::actingAs($admin)->test('admin.invites')
            ->assertSee('总佣金')
            ->assertSee('¥35.00')  // total 1000+2000+500
            ->assertSee('¥10.00')  // pending
            ->assertSee('¥20.00')  // confirmed not transferred
            ->assertSee('¥5.00');  // transferred
    }

    public function test_admin_can_void_a_pending_commission(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $c = $this->commission(User::factory()->create(), User::factory()->create(), 1000, Commission::STATUS_PENDING);

        Livewire::actingAs($admin)->test('admin.invites')->call('voidCommission', $c->id);

        $this->assertSame(Commission::STATUS_VOID, $c->fresh()->status);
    }

    public function test_cannot_void_a_transferred_commission(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $c = $this->commission(User::factory()->create(), User::factory()->create(), 1000, Commission::STATUS_CONFIRMED, transferred: true);

        Livewire::actingAs($admin)->test('admin.invites')
            ->call('voidCommission', $c->id)
            ->assertSet('error', '已划转到余额的佣金不能作废');

        $this->assertFalse($c->fresh()->isVoid());
    }

    public function test_leaderboard_ranks_referrers_by_headcount(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $top = User::factory()->create(['email' => 'topreferrer@x.com']);
        User::factory()->create()->forceFill(['referred_by_user_id' => $top->id])->save();
        User::factory()->create()->forceFill(['referred_by_user_id' => $top->id])->save();

        Livewire::actingAs($admin)->test('admin.invites')
            ->set('tab', 'leaderboard')
            ->assertSee('topreferrer@x.com')
            ->assertSee('2 人');
    }

    public function test_commission_csv_export_runs(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->commission(User::factory()->create(), User::factory()->create(), 1000, Commission::STATUS_CONFIRMED);

        Livewire::actingAs($admin)->test('admin.invites')
            ->set('tab', 'commissions')
            ->call('exportCsv')
            ->assertFileDownloaded();
    }

    public function test_commission_status_filter(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $r = User::factory()->create();
        $pendingReferred = User::factory()->create(['email' => 'pendingguy@x.com']);
        $confReferred = User::factory()->create(['email' => 'confguy@x.com']);
        $this->commission($r, $pendingReferred, 1000, Commission::STATUS_PENDING);
        $this->commission($r, $confReferred, 2000, Commission::STATUS_CONFIRMED);

        Livewire::actingAs($admin)->test('admin.invites')
            ->set('tab', 'commissions')
            ->set('statusFilter', 'pending')
            ->assertSee('pendingguy@x.com')
            ->assertDontSee('confguy@x.com');
    }
}
