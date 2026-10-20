<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\InviteCode;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Services\CommissionService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ReferralCommissionTest extends TestCase
{
    use RefreshDatabase;

    private function makePlan(): Plan
    {
        return Plan::create([
            'name' => '20GB 基础共享线路',
            'type' => Plan::TYPE_SHARED,
            'group_id' => 1,
            'transfer_enable' => 20 * 1024 * 1024 * 1024,
            'prices' => ['month' => 1500],
            'sell' => true,
        ]);
    }

    public function test_registering_with_a_valid_invite_code_links_the_referrer(): void
    {
        $referrer = User::factory()->create();
        $code = InviteCode::create(['user_id' => $referrer->id]);

        Livewire::withQueryParams(['ref' => $code->code])->test('auth.register')
            ->set('name', 'New User')
            ->set('email', 'new@example.com')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register');

        $newUser = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertSame($referrer->id, $newUser->referred_by_user_id);
        $this->assertSame($code->id, $newUser->invite_code_id);
    }

    public function test_registering_with_one_of_several_codes_records_which_one_was_used(): void
    {
        $referrer = User::factory()->create();
        $codeA = InviteCode::create(['user_id' => $referrer->id]);
        $codeB = InviteCode::create(['user_id' => $referrer->id]);

        Livewire::withQueryParams(['ref' => $codeB->code])->test('auth.register')
            ->set('name', 'New User')
            ->set('email', 'new-b@example.com')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register');

        $newUser = User::where('email', 'new-b@example.com')->firstOrFail();
        $this->assertSame($referrer->id, $newUser->referred_by_user_id);
        $this->assertSame($codeB->id, $newUser->invite_code_id);
        $this->assertSame(0, $codeA->registeredUsers()->count());
        $this->assertSame(1, $codeB->registeredUsers()->count());
    }

    public function test_registering_with_an_invalid_code_does_not_block_registration(): void
    {
        Livewire::withQueryParams(['ref' => 'NOPE'])->test('auth.register')
            ->set('name', 'New User')
            ->set('email', 'new2@example.com')
            ->set('password', 'password123')
            ->set('password_confirmation', 'password123')
            ->call('register');

        $newUser = User::where('email', 'new2@example.com')->firstOrFail();
        $this->assertNull($newUser->referred_by_user_id);
    }

    public function test_paid_order_from_a_referred_user_awards_commission_at_configured_rate(): void
    {
        Setting::set('commission_rate', 20);

        $referrer = User::factory()->create();
        $buyer = User::factory()->create(['referred_by_user_id' => $referrer->id]);
        $plan = $this->makePlan();

        $orderService = app(OrderService::class);
        $order = $orderService->createOrder($buyer, $plan, 'month');
        $orderService->markPaid($order);

        $commission = Commission::where('user_id', $referrer->id)->firstOrFail();
        $this->assertSame(300, $commission->amount); // 20% of 1500
        $this->assertSame($buyer->id, $commission->referred_user_id);
        $this->assertSame(Commission::STATUS_CONFIRMED, $commission->status);
    }

    public function test_no_commission_when_buyer_has_no_referrer(): void
    {
        $buyer = User::factory()->create();
        $plan = $this->makePlan();

        $orderService = app(OrderService::class);
        $order = $orderService->createOrder($buyer, $plan, 'month');
        $orderService->markPaid($order);

        $this->assertSame(0, Commission::count());
    }

    public function test_commission_stays_pending_until_hold_period_elapses(): void
    {
        Setting::set('commission_rate', 10);
        Setting::set('commission_hold_days', 3);

        $referrer = User::factory()->create();
        $buyer = User::factory()->create(['referred_by_user_id' => $referrer->id]);
        $plan = $this->makePlan();

        $orderService = app(OrderService::class);
        $order = $orderService->createOrder($buyer, $plan, 'month');
        $orderService->markPaid($order);

        $commission = Commission::where('user_id', $referrer->id)->firstOrFail();
        $this->assertSame(Commission::STATUS_PENDING, $commission->status);

        // Not yet due.
        app(CommissionService::class)->confirmDueCommissions($referrer);
        $this->assertSame(Commission::STATUS_PENDING, $commission->fresh()->status);

        // Backdate past the hold period and confirm. created_at isn't
        // mass-assignable via update()'s fillable guard, so set it directly.
        $commission->created_at = now()->subDays(4);
        $commission->save();
        app(CommissionService::class)->confirmDueCommissions($referrer);
        $this->assertSame(Commission::STATUS_CONFIRMED, $commission->fresh()->status);
    }

    public function test_transfer_via_service_moves_confirmed_commission_into_balance(): void
    {
        Setting::set('commission_rate', 10);

        $referrer = User::factory()->create(['balance' => 0]);
        $buyer = User::factory()->create(['referred_by_user_id' => $referrer->id]);
        $plan = $this->makePlan();

        $orderService = app(OrderService::class);
        $order = $orderService->createOrder($buyer, $plan, 'month');
        $orderService->markPaid($order);

        $amount = app(CommissionService::class)->transfer($referrer);

        $this->assertSame(150, $amount);
        $this->assertSame(150, $referrer->fresh()->balance);
        $this->assertNotNull(Commission::first()->transferred_at);
    }

    public function test_transfer_fails_when_nothing_available(): void
    {
        $referrer = User::factory()->create();

        $this->expectException(ValidationException::class);
        app(CommissionService::class)->transfer($referrer);
    }

    public function test_customer_can_generate_invite_code_and_transfer(): void
    {
        Setting::set('commission_rate', 10);

        $referrer = User::factory()->create(['balance' => 0]);
        $buyer = User::factory()->create(['referred_by_user_id' => $referrer->id]);
        $plan = $this->makePlan();
        $orderService = app(OrderService::class);
        $orderService->markPaid($orderService->createOrder($buyer, $plan, 'month'));

        $component = Livewire::actingAs($referrer)->test('invite')
            ->call('generateCode');

        $this->assertSame(1, InviteCode::where('user_id', $referrer->id)->count());

        $component->call('transfer')
            ->assertSet('error', '');

        $this->assertSame(150, $referrer->fresh()->balance);
    }

    public function test_customer_cannot_generate_more_than_the_configured_limit(): void
    {
        Setting::set('max_invite_codes', 2);

        $user = User::factory()->create();
        $component = Livewire::actingAs($user)->test('invite');

        $component->call('generateCode')->assertSet('error', '');
        $component->call('generateCode')->assertSet('error', '');
        $component->call('generateCode')
            ->assertSet('error', '最多只能生成 2 个邀请码');

        $this->assertSame(2, InviteCode::where('user_id', $user->id)->count());
    }

    public function test_invite_code_limit_defaults_to_five_when_unset(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            InviteCode::create(['user_id' => $user->id]);
        }

        $component = Livewire::actingAs($user)->test('invite');
        $component->call('generateCode')
            ->assertSet('error', '最多只能生成 5 个邀请码');

        $this->assertSame(5, InviteCode::where('user_id', $user->id)->count());
    }

    public function test_admin_can_view_invites_page(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test('admin.invites')->assertOk();
    }

    public function test_single_use_invite_code_is_rejected_on_second_registration_when_never_expires_is_off(): void
    {
        Setting::set('invite_code_never_expires', false);
        $referrer = User::factory()->create();
        $code = InviteCode::create(['user_id' => $referrer->id]);

        Livewire::withQueryParams(['ref' => $code->code])->test('auth.register')
            ->set('name', 'First')->set('email', 'first@example.com')
            ->set('password', 'password123')->set('password_confirmation', 'password123')
            ->call('register');

        $this->assertSame($referrer->id, User::where('email', 'first@example.com')->first()->referred_by_user_id);

        Livewire::withQueryParams(['ref' => $code->code])->test('auth.register')
            ->set('name', 'Second')->set('email', 'second@example.com')
            ->set('password', 'password123')->set('password_confirmation', 'password123')
            ->call('register');

        $this->assertNull(User::where('email', 'second@example.com')->first()->referred_by_user_id);
    }

    public function test_reusable_invite_code_works_for_multiple_registrations_by_default(): void
    {
        $referrer = User::factory()->create();
        $code = InviteCode::create(['user_id' => $referrer->id]);

        foreach (['a@example.com', 'b@example.com'] as $email) {
            Livewire::withQueryParams(['ref' => $code->code])->test('auth.register')
                ->set('name', 'Test')->set('email', $email)
                ->set('password', 'password123')->set('password_confirmation', 'password123')
                ->call('register');
        }

        $this->assertSame(2, $code->registeredUsers()->count());
    }

    public function test_commission_first_payment_only_skips_renewal_orders(): void
    {
        Setting::set('commission_rate', 10);
        Setting::set('commission_first_payment_only', true);

        $referrer = User::factory()->create();
        $buyer = User::factory()->create(['referred_by_user_id' => $referrer->id]);
        $plan = $this->makePlan();

        $orderService = app(OrderService::class);
        $orderService->markPaid($orderService->createOrder($buyer, $plan, 'month'));
        $this->assertSame(1, Commission::count());

        $order2 = $orderService->createOrder($buyer, $plan, 'month');
        $orderService->markPaid($order2);

        $this->assertSame(1, Commission::count());
    }

    public function test_three_tier_commission_splits_pool_across_referral_chain(): void
    {
        Setting::set('commission_rate', 30);
        Setting::set('three_tier_commission_enabled', true);
        Setting::set('commission_tier1_rate', 60);
        Setting::set('commission_tier2_rate', 30);
        Setting::set('commission_tier3_rate', 10);

        $grandparent = User::factory()->create();
        $parent = User::factory()->create(['referred_by_user_id' => $grandparent->id]);
        $buyer = User::factory()->create(['referred_by_user_id' => $parent->id]);
        $plan = $this->makePlan();

        $orderService = app(OrderService::class);
        $orderService->markPaid($orderService->createOrder($buyer, $plan, 'month'));

        // pool = 30% of 1500 = 450
        $this->assertSame(270, Commission::where('user_id', $parent->id)->sum('amount'));
        $this->assertSame(135, Commission::where('user_id', $grandparent->id)->sum('amount'));
        $this->assertSame(2, Commission::count());
    }

    public function test_confirmed_commission_transfers_to_balance_and_there_is_no_cash_withdrawal_path(): void
    {
        Setting::set('commission_rate', 10);

        $referrer = User::factory()->create();
        $buyer = User::factory()->create(['referred_by_user_id' => $referrer->id]);
        $plan = $this->makePlan();
        $orderService = app(OrderService::class);
        $orderService->markPaid($orderService->createOrder($buyer, $plan, 'month'));

        $service = app(CommissionService::class);
        // 10% of 1500 = 150 confirmed commission, all transferable to balance.
        $this->assertSame(150, $service->untransferredCommission($referrer));

        $moved = $service->transfer($referrer);
        $this->assertSame(150, $moved);
        $this->assertSame(150, (int) $referrer->fresh()->balance);
        $this->assertSame(0, $service->untransferredCommission($referrer));

        // The site is credit-only: commission → balance → spent on plans.
        // There is deliberately no cash-out path anywhere.
        $this->assertFalse(method_exists($service, 'requestWithdrawal'));
        $this->assertFalse(method_exists($service, 'availableForWithdrawal'));
    }

    public function test_invite_page_offers_transfer_to_balance_and_never_a_withdrawal_form(): void
    {
        $referrer = User::factory()->create();

        Livewire::actingAs($referrer)->test('invite')
            ->assertSee('划转到余额')
            ->assertDontSee('申请提现')
            ->assertDontSee('提现方式');
    }
}
