<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Plan;
use App\Models\SubscriptionInstance;
use App\Models\User;
use App\Services\OnipProvisioningService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class IspCountryPricingTest extends TestCase
{
    use RefreshDatabase;

    private function dynamicIspPlan(): Plan
    {
        return Plan::create([
            'name' => '静态ISP｜任选地区',
            'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 50 * 1024 * 1024 * 1024,
            'prices' => ['month' => 5500, 'quarter' => 14500, 'year' => 52500],
            'country_prices' => [
                'GBR' => ['month' => 7500, 'quarter' => 20000, 'year' => 70000],
                'JPN' => ['month' => 8800],
            ],
            'resource_profile' => ['provider' => 'onip_dynamic', 'ispType' => 2],
            'sell' => true, 'allow_renewal' => true,
        ]);
    }

    private function line(string $country, ?float $cost = null): array
    {
        $lineId = 'L-'.$country;

        // Seed the server-side authoritative cache the way a real searchLines()
        // would — the order path prices from here, not from the request's
        // line_snapshot (which is client-controllable and no longer trusted).
        app(OnipProvisioningService::class)->cacheLine([
            'id' => $lineId, 'countryCode' => $country, 'cityCode' => null,
            'price' => $cost ?? 20, 'currency' => 'CNY',
        ]);

        $snapshot = ['countryCode' => $country];
        if ($cost !== null) {
            $snapshot['price'] = $cost; // onip monthly cost, yuan
        }

        return ['provider' => 'onip', 'lineId' => $lineId, 'line_snapshot' => $snapshot];
    }

    private function autoPricedPlan(array $countryPrices = []): Plan
    {
        return Plan::create([
            'name' => '静态ISP｜自动定价',
            'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 50 * 1024 * 1024 * 1024,
            'prices' => ['month' => 5500],
            'country_prices' => $countryPrices ?: null,
            'resource_profile' => [
                'provider' => 'onip_dynamic', 'ispType' => 2,
                'auto_pricing' => ['enabled' => true, 'relay' => 15, 'profit' => 20, 'round' => 5],
            ],
            'sell' => true, 'allow_renewal' => true,
        ]);
    }

    public function test_country_without_a_configured_price_is_not_for_sale(): void
    {
        // When a plan uses per-country pricing, a country we never priced (USA
        // here) must NOT be sellable — no silent fallback to the base price.
        $plan = $this->dynamicIspPlan();
        $user = User::factory()->create();

        $this->expectException(ValidationException::class);
        app(OrderService::class)->createOrder($user, $plan, 'month', null, $this->line('USA'));
    }

    public function test_plan_without_per_country_pricing_sells_every_country_at_base(): void
    {
        // A dynamic plan with no country_prices at all is unrestricted: every
        // in-stock line sells at the base price (unchanged behaviour).
        $plan = Plan::create([
            'name' => '静态ISP｜统一价',
            'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 50 * 1024 * 1024 * 1024,
            'prices' => ['month' => 4900],
            'resource_profile' => ['provider' => 'onip_dynamic', 'ispType' => 2],
            'sell' => true, 'allow_renewal' => true,
        ]);
        $user = User::factory()->create();

        $order = app(OrderService::class)->createOrder($user, $plan, 'month', null, $this->line('USA'));
        $this->assertSame(4900, $order->total_amount);
    }

    public function test_country_override_applies_full_period_set(): void
    {
        $plan = $this->dynamicIspPlan();
        $user = User::factory()->create();

        $this->assertSame(7500, app(OrderService::class)->createOrder($user, $plan, 'month', null, $this->line('GBR'))->total_amount);
        $this->assertSame(70000, app(OrderService::class)->createOrder($user, $plan, 'year', null, $this->line('GBR'))->total_amount);
    }

    public function test_a_period_the_country_was_not_priced_for_is_not_for_sale(): void
    {
        // JPN only has a month price. Month sells; quarter (unpriced for JPN)
        // is rejected rather than silently billed at the base quarter price.
        $plan = $this->dynamicIspPlan();
        $user = User::factory()->create();

        $this->assertSame(8800, app(OrderService::class)->createOrder($user, $plan, 'month', null, $this->line('JPN'))->total_amount);

        $this->expectException(ValidationException::class);
        app(OrderService::class)->createOrder($user, $plan, 'quarter', null, $this->line('JPN'));
    }

    public function test_cheapest_month_price_reflects_min_across_base_and_overrides(): void
    {
        // base 5500 is cheaper than GBR 7500 / JPN 8800 → "起" price is 5500.
        $this->assertSame(5500, $this->dynamicIspPlan()->cheapestMonthPrice());

        // if a country is cheaper than base, that becomes the "from" price
        $plan = $this->dynamicIspPlan();
        $plan->update(['country_prices' => ['IDN' => ['month' => 3900]] + $plan->country_prices]);
        $this->assertSame(3900, $plan->fresh()->cheapestMonthPrice());
    }

    public function test_auto_price_adds_fixed_relay_and_profit_then_rounds_to_five(): void
    {
        // cost + ¥15 relay + ¥20 profit, rounded to the nearest ¥5.
        $this->assertSame(4500, Plan::autoPrice(12, 'month', 15, 20, 5)); // 47 → 45
        $this->assertSame(6500, Plan::autoPrice(30, 'month', 15, 20, 5)); // 65 → 65
        $this->assertSame(5000, Plan::autoPrice(14, 'month', 15, 20, 5)); // 49 → 50
        // A quarter bills 3 months of (cost+relay+profit): (30+35)×3 = ¥195.
        $this->assertSame(19500, Plan::autoPrice(30, 'quarter', 15, 20, 5));
    }

    public function test_longer_periods_apply_their_discount(): void
    {
        // ¥30 line = ¥65/month. quarter 5% off, half 8%, year 12%.
        $this->assertSame(18500, Plan::autoPrice(30, 'quarter', 15, 20, 5, 5));    // 195×0.95 → 185
        $this->assertSame(36000, Plan::autoPrice(30, 'half_year', 15, 20, 5, 8));  // 390×0.92 → 360
        $this->assertSame(68500, Plan::autoPrice(30, 'year', 15, 20, 5, 12));      // 780×0.88 → 685
    }

    public function test_auto_price_can_apply_a_monthly_adjustment_before_period_scaling(): void
    {
        $this->assertSame(4500, Plan::autoPrice(12, 'month', 20, 20, 5, 0, -5));      // 47 → 45
        $this->assertSame(13500, Plan::autoPrice(12, 'quarter', 20, 20, 5, 5, -5));   // 47×3×0.95 → 135
        $this->assertSame(26000, Plan::autoPrice(12, 'half_year', 20, 20, 5, 8, -5)); // 47×6×0.92 → 260
        $this->assertSame(49500, Plan::autoPrice(12, 'year', 20, 20, 5, 12, -5));     // 47×12×0.88 → 495
    }

    public function test_sellprice_applies_the_plans_period_discounts(): void
    {
        // Default discounts (5/8/12) come from the plan's auto_pricing config.
        $plan = $this->autoPricedPlan();
        $snap = ['countryCode' => 'BRA', 'price' => 30];

        $this->assertSame(6500, $plan->sellPrice('month', $snap));      // no discount
        $this->assertSame(18500, $plan->sellPrice('quarter', $snap));   // 5% off
        $this->assertSame(68500, $plan->sellPrice('year', $snap));      // 12% off
    }

    public function test_sellprice_applies_the_plans_monthly_adjustment(): void
    {
        $plan = $this->autoPricedPlan();
        $profile = $plan->resource_profile;
        $profile['auto_pricing']['relay'] = 20;
        $profile['auto_pricing']['monthly_adjustment'] = -5;
        $plan->update(['resource_profile' => $profile]);

        $snap = ['countryCode' => 'THA', 'price' => 12];

        $this->assertSame(4500, $plan->fresh()->sellPrice('month', $snap));
        $this->assertSame(13500, $plan->fresh()->sellPrice('quarter', $snap));
    }

    public function test_auto_pricing_sells_an_unconfigured_country_from_its_onip_cost(): void
    {
        $plan = $this->autoPricedPlan();
        $user = User::factory()->create();

        // BRA has no manual price; a ¥30 line → 30+15+20 = ¥65/month.
        $order = app(OrderService::class)->createOrder($user, $plan, 'month', null, $this->line('BRA', 30));
        $this->assertSame(6500, $order->total_amount);
    }

    public function test_manual_country_price_still_wins_when_auto_pricing_is_on(): void
    {
        $plan = $this->autoPricedPlan(['GBR' => ['month' => 9900]]);
        $user = User::factory()->create();

        // GBR is manually priced ¥99 — used verbatim, not the ¥45 the cost implies.
        $order = app(OrderService::class)->createOrder($user, $plan, 'month', null, $this->line('GBR', 30));
        $this->assertSame(9900, $order->total_amount);
    }

    public function test_a_forged_line_price_is_ignored_for_the_cached_onip_cost(): void
    {
        // SECURITY: the whole line pick arrives as client-controllable Livewire
        // state. A ¥0.01 forged cost must NOT undercut the real ¥30 the server
        // recorded — otherwise a real static IP could be bought for pennies.
        $plan = $this->autoPricedPlan();
        $user = User::factory()->create();

        app(OnipProvisioningService::class)->cacheLine([
            'id' => 'L-BRA', 'countryCode' => 'BRA', 'price' => 30,
        ]);

        $forged = ['provider' => 'onip', 'lineId' => 'L-BRA', 'line_snapshot' => ['countryCode' => 'BRA', 'price' => 0.01]];
        $order = app(OrderService::class)->createOrder($user, $plan, 'month', null, $forged);

        // Priced from the real ¥30 (30+15+20 = ¥65), not the forged ¥0.01.
        $this->assertSame(6500, $order->total_amount);
    }

    public function test_order_is_rejected_for_a_line_the_server_never_vetted(): void
    {
        // No server-cached cost (never went through searchLines) → the price
        // can't be trusted, so reject rather than fall back to request data.
        $plan = $this->autoPricedPlan();
        $user = User::factory()->create();

        $this->expectException(ValidationException::class);
        app(OrderService::class)->createOrder($user, $plan, 'month', null, [
            'provider' => 'onip', 'lineId' => 'L-UNCACHED',
            'line_snapshot' => ['countryCode' => 'BRA', 'price' => 5],
        ]);
    }

    public function test_auto_priced_line_renews_at_the_same_computed_price(): void
    {
        // Renewal recomputes from the country + onip cost persisted on the
        // instance, so an auto-priced line renews at the same ¥65, not the base.
        $plan = $this->autoPricedPlan();
        $user = User::factory()->create();

        $instance = SubscriptionInstance::create([
            'user_id' => $user->id, 'plan_id' => $plan->id,
            'transfer_enable' => $plan->transfer_enable,
            'status' => SubscriptionInstance::STATUS_ACTIVE,
            'expired_at' => now()->addDays(5),
            'meta' => ['country' => 'BRA', 'onip_price' => 30],
        ]);

        $renew = app(OrderService::class)->createRenewalOrder($user, $instance, 'month');
        $this->assertSame(6500, $renew->total_amount);
    }

    public function test_coupon_applies_to_country_adjusted_price(): void
    {
        $plan = $this->dynamicIspPlan();
        $user = User::factory()->create();
        Coupon::create(['code' => 'OFF10', 'type' => 'percent', 'value' => 10, 'enabled' => true]);

        // GBR month 7500, 10% off = 6750
        $order = app(OrderService::class)->createOrder($user, $plan, 'month', 'OFF10', $this->line('GBR'));
        $this->assertSame(750, $order->discount_amount);
        $this->assertSame(6750, $order->total_amount);
    }
}
