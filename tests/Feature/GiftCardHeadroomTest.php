<?php

namespace Tests\Feature;

use App\Models\BalanceTransaction;
use App\Models\GiftCard;
use App\Models\Tenant;
use App\Models\TenantWholesale;
use App\Models\User;
use App\Services\GiftCardService;
use App\Services\WholesaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Minting a gift card does not debit 进货余额 — the card becomes an order
 * later, and that order settles wholesale on its full retail total whatever
 * paid for it, so charging at mint would bill the agent twice for one sale.
 *
 * What it must not allow is overselling: an agent with ¥400 of credit issuing
 * ¥5000 of cards, whose customers redeem happily and then hit "开通失败" at
 * checkout one after another, on the platform's nodes.
 */
class GiftCardHeadroomTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenant()) {
            tenancy()->end();
        }
        foreach (Tenant::all() as $t) {
            $t->delete();
        }
        parent::tearDown();
    }

    private function shop(int $discount, int $credit, ?string $id = null): Tenant
    {
        // One id per test. tenant_wholesale is a central table keyed by
        // tenant_id, so a reused id inherits the previous test's balance, and
        // its sqlite file can outlive it too.
        $id ??= 'shop'.strtolower(preg_replace('/[^a-z]/i', '', $this->name()));
        $t = Tenant::create(['id' => $id, 'agent_discount' => $discount]);
        app(WholesaleService::class)->credit($id, $credit);

        return $t;
    }

    private function agent(Tenant $t): User
    {
        return $t->run(function () {
            $u = User::create(['name' => '代理', 'email' => 'a@example.com', 'password' => 'x']);
            $u->forceFill(['is_admin' => true, 'admin_role' => User::ROLE_SUPER])->save();

            return $u;
        });
    }

    public function test_headroom_is_the_balance_itself_whatever_the_discount(): void
    {
        // Balance is denominated in retail — the discount was already taken
        // when the agent bought it — so ¥400 of balance issues ¥400 of cards
        // no matter what rate they are on.
        foreach ([0, 30, 90] as $discount) {
            $t = $this->shop($discount, 40000, "cardrate{$discount}");
            $t->run(fn () => $this->assertSame(
                40000, app(WholesaleService::class)->giftCardHeadroom($t)
            ));
        }
    }

    public function test_the_cash_owed_for_a_top_up_is_the_discounted_price(): void
    {
        // The only place the discount is applied. ¥400 of balance costs an
        // agent on 30% exactly ¥280 — quote face value by mistake and the
        // whole margin is given away.
        $t = $this->shop(30, 0);

        $this->assertSame(28000, app(WholesaleService::class)->cashPriceFor($t, 40000));
    }

    public function test_issued_cards_eat_headroom_and_deleting_one_gives_it_back(): void
    {
        $t = $this->shop(0, 10000);   // no discount: ¥100 credit backs ¥100

        $t->run(function () use ($t) {
            $ws = app(WholesaleService::class);
            $card = GiftCard::create(['code' => 'A-B-C', 'amount' => 6000]);
            $this->assertSame(4000, $ws->giftCardHeadroom($t));

            $card->delete();
            $this->assertSame(10000, $ws->giftCardHeadroom($t));
        });
    }

    public function test_a_redeemed_but_unspent_card_still_counts_against_the_agent(): void
    {
        // Redeeming only moves the face value from "unredeemed card" to
        // "customer account balance" — until an order actually consumes it,
        // it is still a claim on this agent's 进货余额 (see the headroom
        // docblock). Real ¥100 redeem / ¥90 spend / ¥10 example: the ¥10
        // must keep occupying headroom, not silently vanish.
        $t = $this->shop(0, 10000); // ¥100 credit

        $t->run(function () use ($t) {
            $customer = User::create(['name' => 'c', 'email' => 'c@example.com', 'password' => 'x']);
            $card = GiftCard::create(['code' => 'A-B-C', 'amount' => 10000]);
            app(GiftCardService::class)->redeem($customer, $card->code);

            // Redeemed, none spent yet — the full ¥100 still occupies headroom.
            $this->assertSame(0, app(WholesaleService::class)->giftCardHeadroom($t));

            // Customer spends ¥90 of it on a delivered order — simulated
            // directly (the accounting question here is the headroom formula,
            // not the reserve()/settlement pipeline, which has its own tests).
            // A real settlement debits both sides together: the customer's
            // balance AND this tenant's actual 进货余额.
            $customer->changeBalance(-9000, BalanceTransaction::TYPE_ORDER_PAYMENT, 'test spend');
            TenantWholesale::where('tenant_id', $t->getTenantKey())->decrement('balance', 9000);

            // ¥10 balance is still unspent — headroom must not free it up.
            $this->assertSame(0, app(WholesaleService::class)->giftCardHeadroom($t));
        });
    }

    public function test_direct_gateway_top_ups_also_count_against_headroom(): void
    {
        // Not just gift-card money — any balance a customer is sitting on
        // will eventually consume 进货余额 when they spend it, regardless of
        // how they got it.
        $t = $this->shop(0, 10000); // ¥100 credit

        $t->run(function () use ($t) {
            $customer = User::create(['name' => 'c', 'email' => 'c@example.com', 'password' => 'x']);
            $customer->changeBalance(3000, BalanceTransaction::TYPE_ORDER_PAYMENT, 'gateway top-up');

            $this->assertSame(7000, app(WholesaleService::class)->giftCardHeadroom($t));
        });
    }

    public function test_an_agent_cannot_mint_past_the_headroom(): void
    {
        $t = $this->shop(0, 10000);
        $agent = $this->agent($t);

        tenancy()->initialize($t);
        Livewire::actingAs($agent)->test('admin.giftcards')
            ->set('amount', '60')->set('quantity', 3)     // ¥180 against ¥100
            ->call('generate')
            ->assertSet('error', fn ($e) => str_contains($e, '进货余额不足'));

        $this->assertSame(0, GiftCard::count(), 'nothing may be issued when the batch is refused');
        tenancy()->end();
    }

    public function test_an_agent_can_mint_within_the_headroom(): void
    {
        $t = $this->shop(0, 10000);
        $agent = $this->agent($t);

        tenancy()->initialize($t);
        Livewire::actingAs($agent)->test('admin.giftcards')
            ->set('amount', '25')->set('quantity', 4)     // exactly ¥100
            ->call('generate')
            ->assertSet('error', '');

        $this->assertSame(4, GiftCard::count());
        $this->assertSame(0, app(WholesaleService::class)->giftCardHeadroom($t));
        tenancy()->end();
    }

    public function test_the_dashboard_shows_the_agent_their_remaining_headroom(): void
    {
        $t = $this->shop(0, 10000);
        $agent = $this->agent($t);
        $t->run(fn () => GiftCard::create(['code' => 'A-B-C', 'amount' => 2500]));

        tenancy()->initialize($t);
        Livewire::actingAs($agent)->test('admin.dashboard')
            ->assertSee('还可发行卡密')
            ->assertSee('¥75.00');          // ¥100 credit − ¥25 outstanding
        tenancy()->end();
    }

    public function test_the_liability_tile_is_worded_for_whoever_is_reading_it(): void
    {
        // Same number, different meaning: to 站长 it is store credit owed to
        // customers; to an agent it is cash already collected that has not yet
        // cost them any 进货 — it only does when the customer spends it.
        $t = $this->shop(0, 10000);
        $agent = $this->agent($t);
        $t->run(fn () => User::where('email', 'a@example.com')->first());

        tenancy()->initialize($t);
        Livewire::actingAs($agent)->test('admin.dashboard')
            ->assertSee('客户已充值未消费，消费时才扣进货')
            ->assertDontSee('欠客户的 store credit');
        tenancy()->end();

        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);
        Livewire::actingAs($admin)->test('admin.dashboard')
            ->assertSee('欠客户的 store credit')
            ->assertDontSee('客户已充值未消费');
    }

    public function test_the_main_site_dashboard_has_no_such_tile(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);

        Livewire::actingAs($admin)->test('admin.dashboard')->assertDontSee('还可发行卡密');
    }

    public function test_the_main_site_mints_freely(): void
    {
        // 主站 issues against nothing — there is no credit to run out of.
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);

        Livewire::actingAs($admin)->test('admin.giftcards')
            ->set('amount', '99999')->set('quantity', 10)
            ->call('generate')
            ->assertSet('error', '');

        $this->assertSame(10, GiftCard::count());
    }
}
