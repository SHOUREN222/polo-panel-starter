<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\SubscriptionInstance;
use App\Models\User;
use App\Services\OnipProvisioningService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class DynamicIspLineSelectionTest extends TestCase
{
    use RefreshDatabase;

    private function configureOnip(): void
    {
        Setting::set('provisioner_driver', 'onip');
        Setting::set('onip_app_id', 'id');
        Setting::set('onip_app_secret', 'secret');
    }

    private function makeDynamicPlan(): Plan
    {
        return Plan::create([
            'name' => '静态 ISP｜任选地区',
            'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 50 * 1024 * 1024 * 1024,
            'prices' => ['month' => 3000],
            'resource_profile' => ['provider' => 'onip_dynamic', 'ispType' => 2],
            'sell' => true,
        ]);
    }

    public function test_admin_can_create_a_dynamic_region_dedicated_isp_plan(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test('admin.plans')
            ->call('create')
            ->set('name', '静态 ISP｜任选地区')
            ->set('type', 'dedicated_isp')
            ->set('transfer_enable_gb', '50')
            ->set('prices.month', '30')
            ->set('dynamicMode', true)
            ->set('dynamicIspType', '2')
            ->call('save');

        $plan = Plan::where('name', '静态 ISP｜任选地区')->firstOrFail();
        $this->assertSame('onip_dynamic', $plan->resource_profile['provider']);
        $this->assertSame(2, $plan->resource_profile['ispType']);
        $this->assertTrue($plan->isDynamicIsp());
    }

    public function test_editing_a_dynamic_plan_reloads_its_dynamic_state(): void
    {
        $plan = $this->makeDynamicPlan();
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test('admin.plans')
            ->call('edit', $plan->id)
            ->assertSet('dynamicMode', true)
            ->assertSet('dynamicIspType', '2');
    }

    public function test_customer_page_shows_a_region_picker_button_only_for_dynamic_plans(): void
    {
        $this->makeDynamicPlan();
        Plan::create([
            'name' => '静态 ISP｜固定线路', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1, 'prices' => ['month' => 5000],
            'resource_profile' => ['provider' => 'onip', 'lineId' => '999', 'line_snapshot' => []],
            'sell' => true,
        ]);

        $user = User::factory()->create();

        Livewire::actingAs($user)->test('plans')
            ->assertSee('选地区')
            ->assertSeeInOrder(['静态 ISP｜任选地区', '选地区']);
    }

    public function test_customer_can_browse_by_continent_then_city_to_find_a_line(): void
    {
        $this->configureOnip();
        $plan = $this->makeDynamicPlan();

        Http::fake([
            '*/static/lines/locations/available*' => Http::response([
                'success' => true, 'code' => 'SUCCESS', 'data' => [
                    ['code' => 'USANYSNYC', 'name' => '纽约', 'parentCode' => 'USANYS', 'type' => 'city', 'active' => true, 'cidrs' => [['availableCount' => 253, 'cidr' => '1.2.3.0/24']]],
                    ['code' => 'DEUHESFRA', 'name' => '法兰克福', 'parentCode' => 'DEUHES', 'type' => 'city', 'active' => true, 'cidrs' => [['availableCount' => 253, 'cidr' => '4.5.6.0/24']]],
                ],
            ]),
            '*/static/lines' => Http::response([
                'success' => true, 'code' => 'SUCCESS', 'data' => [
                    'total' => 1,
                    'records' => [[
                        'id' => '1481726073313038336', 'countryCode' => 'USA', 'cityCode' => 'USANYSNYC',
                        'businessTypeCode' => 'other', 'ispType' => 2, 'price' => 11, 'currency' => 'CNY',
                        'quantity' => 2276, 'active' => true, 'status' => 0,
                    ]],
                ],
            ]),
        ]);

        $user = User::factory()->create();

        Livewire::actingAs($user)->test('plans')
            ->call('openLinePicker', $plan->id, 'month')
            ->call('browseContinent', '北美')
            ->assertSet('continentCities.0.code', 'USANYSNYC')
            ->assertDontSee('法兰克福') // 法兰克福 is Europe, must be filtered out of 北美
            ->call('browseCity', 'USANYSNYC', '纽约')
            // Picking a city auto-selects a line for it — no separate
            // businessType choice, since onip's business types are just its
            // own inventory tags, not a real usage restriction.
            ->assertSet('selectedLineId', '1481726073313038336')
            ->assertSet('selectedCityName', '纽约')
            ->assertSee('纽约')
            ->assertDontSee('other'); // the raw businessType tag must not leak into the UI
    }

    public function test_customer_can_browse_city_without_cidrs_when_onip_lines_are_sellable(): void
    {
        $this->configureOnip();
        Cache::flush();
        $plan = $this->makeDynamicPlan();

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/static/lines/locations/available')) {
                return Http::response([
                    'success' => true, 'code' => 'SUCCESS', 'data' => [
                        ['code' => 'THATHATHA', 'name' => '泰国', 'parentCode' => 'THATHA', 'type' => 'city', 'active' => true],
                    ],
                ]);
            }

            $body = json_decode($request->body(), true);
            $this->assertSame('THATHATHA', $body['cityCode'] ?? null);
            $this->assertSame(20, $body['size'] ?? null);

            return Http::response([
                'success' => true, 'code' => 'SUCCESS', 'data' => [
                    'total' => 1,
                    'records' => [[
                        'id' => '1515025831758532608', 'countryCode' => 'THA', 'cityCode' => 'THATHATHA',
                        'businessTypeCode' => 'shopee', 'ispType' => 2, 'price' => 22, 'currency' => 'CNY',
                        'quantity' => 1, 'active' => true, 'status' => 0,
                    ]],
                ],
            ]);
        });

        $user = User::factory()->create();

        Livewire::actingAs($user)->test('plans')
            ->call('openLinePicker', $plan->id, 'month')
            ->call('browseContinent', '亚太')
            ->assertSet('continentCities.0.code', 'THATHATHA')
            ->assertSee('泰国')
            ->call('browseCity', 'THATHATHA', '泰国')
            ->assertSet('selectedLineId', '1515025831758532608')
            ->assertSet('selectedCityName', '泰国');
    }

    public function test_customer_can_search_and_select_a_real_line_then_buy(): void
    {
        $this->configureOnip();
        $plan = $this->makeDynamicPlan();

        Http::fake(fn ($request) => Http::response([
            'success' => true, 'code' => 'SUCCESS', 'data' => [
                'total' => 1,
                'records' => [[
                    'id' => '1481726073313038336', 'countryCode' => 'USA', 'cityCode' => 'USANYSNYC',
                    'businessTypeCode' => 'other', 'ispType' => 2, 'price' => 11, 'currency' => 'CNY',
                    'quantity' => 2276, 'active' => true, 'status' => 0,
                ]],
            ],
        ]));

        $user = User::factory()->create();

        $component = Livewire::actingAs($user)->test('plans')
            ->call('openLinePicker', $plan->id, 'month')
            ->set('lineSearchCountry', 'USA')
            ->call('searchLines')
            ->assertSet('lineSearchResults.0.id', '1481726073313038336');

        $component->call('selectLine', '1481726073313038336')
            ->assertSet('selectedLineId', '1481726073313038336')
            ->call('confirmDynamicBuy');

        $order = Order::where('plan_id', $plan->id)->firstOrFail();
        $this->assertSame('onip', $order->selected_resource_profile['provider']);
        $this->assertSame('1481726073313038336', $order->selected_resource_profile['lineId']);
        $this->assertSame('USANYSNYC', $order->selected_resource_profile['line_snapshot']['cityCode']);
    }

    public function test_search_hides_lines_in_countries_we_have_not_priced(): void
    {
        $this->configureOnip();
        // Plan prices only USA; the search returns a USA line and a DEU line,
        // but DEU (unpriced) must be filtered out of the sellable results.
        $plan = Plan::create([
            'name' => '静态 ISP｜差异化', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1, 'prices' => ['month' => 5500],
            'country_prices' => ['USA' => ['month' => 4500]],
            'resource_profile' => ['provider' => 'onip_dynamic', 'ispType' => 2],
            'sell' => true,
        ]);

        Http::fake(fn ($request) => Http::response([
            'success' => true, 'code' => 'SUCCESS', 'data' => [
                'total' => 2,
                'records' => [
                    ['id' => 'US1', 'countryCode' => 'USA', 'cityCode' => 'USANYSNYC', 'businessTypeCode' => 'other', 'ispType' => 2, 'price' => 11, 'currency' => 'CNY', 'quantity' => 100, 'active' => true, 'status' => 0],
                    ['id' => 'DE1', 'countryCode' => 'DEU', 'cityCode' => 'DEUHESFRA', 'businessTypeCode' => 'other', 'ispType' => 2, 'price' => 13, 'currency' => 'CNY', 'quantity' => 100, 'active' => true, 'status' => 0],
                ],
            ],
        ]));

        $user = User::factory()->create();

        Livewire::actingAs($user)->test('plans')
            ->call('openLinePicker', $plan->id, 'month')
            ->set('lineSearchCountry', 'USA')
            ->call('searchLines')
            ->assertCount('lineSearchResults', 1)
            ->assertSet('lineSearchResults.0.id', 'US1');
    }

    public function test_auto_pricing_shows_lines_in_countries_without_a_manual_price(): void
    {
        $this->configureOnip();
        // Auto-pricing on: even DEU (no manual price) stays sellable, priced
        // from its own onip cost — the opposite of the plain restricted plan.
        $plan = Plan::create([
            'name' => '静态 ISP｜自动定价', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1, 'prices' => ['month' => 5500],
            'country_prices' => ['USA' => ['month' => 4500]],
            'resource_profile' => [
                'provider' => 'onip_dynamic', 'ispType' => 2,
                'auto_pricing' => ['enabled' => true, 'relay' => 15, 'profit' => 20, 'round' => 5],
            ],
            'sell' => true,
        ]);

        Http::fake(fn ($request) => Http::response([
            'success' => true, 'code' => 'SUCCESS', 'data' => [
                'total' => 2,
                'records' => [
                    ['id' => 'US1', 'countryCode' => 'USA', 'cityCode' => 'USANYSNYC', 'businessTypeCode' => 'other', 'ispType' => 2, 'price' => 11, 'currency' => 'CNY', 'quantity' => 100, 'active' => true, 'status' => 0],
                    ['id' => 'DE1', 'countryCode' => 'DEU', 'cityCode' => 'DEUHESFRA', 'businessTypeCode' => 'other', 'ispType' => 2, 'price' => 13, 'currency' => 'CNY', 'quantity' => 100, 'active' => true, 'status' => 0],
                ],
            ],
        ]));

        $user = User::factory()->create();

        Livewire::actingAs($user)->test('plans')
            ->call('openLinePicker', $plan->id, 'month')
            ->set('lineSearchCountry', 'DEU')
            ->call('searchLines')
            ->assertCount('lineSearchResults', 2);
    }

    public function test_card_from_price_reflects_the_real_cheapest_auto_price(): void
    {
        $this->configureOnip();
        Cache::flush();
        $plan = Plan::create([
            'name' => '静态 ISP｜起价', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1, 'prices' => ['month' => 5500],
            'resource_profile' => [
                'provider' => 'onip_dynamic', 'ispType' => 2,
                'auto_pricing' => ['enabled' => true, 'relay' => 15, 'profit' => 20, 'round' => 5],
            ],
            'sell' => true, 'show' => true,
        ]);

        // Cheapest in-stock line costs ¥12 → 12+35 = ¥47 → ¥45 "from" price,
        // NOT the ¥55 base price.
        Http::fake(fn ($request) => Http::response([
            'success' => true, 'code' => 'SUCCESS', 'data' => [
                'total' => 2,
                'records' => [
                    ['id' => 'A', 'countryCode' => 'USA', 'ispType' => 2, 'price' => 12, 'quantity' => 9, 'active' => true, 'status' => 0],
                    ['id' => 'B', 'countryCode' => 'JPN', 'ispType' => 2, 'price' => 24, 'quantity' => 9, 'active' => true, 'status' => 0],
                ],
            ],
        ]));

        $user = User::factory()->create();

        Livewire::actingAs($user)->test('plans')
            ->assertSee('45.00起')
            ->assertDontSee('55.00起');
    }

    public function test_card_from_price_scans_more_than_the_first_onip_page(): void
    {
        $this->configureOnip();
        Cache::flush();
        Plan::create([
            'name' => '静态 ISP｜翻页起价', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1, 'prices' => ['month' => 5500],
            'resource_profile' => [
                'provider' => 'onip_dynamic', 'ispType' => 2,
                'auto_pricing' => ['enabled' => true, 'relay' => 20, 'profit' => 20, 'round' => 5],
            ],
            'sell' => true, 'show' => true,
        ]);

        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $body = json_decode($request->body(), true);
            $requests[] = $body;
            $current = (int) ($body['current'] ?? 0);

            return Http::response([
                'success' => true, 'code' => 'SUCCESS', 'data' => [
                    'total' => 21,
                    'records' => $current === 0
                        ? collect(range(1, 20))->map(fn ($i) => [
                            'id' => 'P0-'.$i, 'countryCode' => 'USA', 'ispType' => 2,
                            'price' => 17, 'quantity' => 1, 'active' => true, 'status' => 0,
                        ])->all()
                        : [[
                            'id' => 'P1-1', 'countryCode' => 'THA', 'ispType' => 2,
                            'price' => 11, 'quantity' => 1, 'active' => true, 'status' => 0,
                        ]],
                ],
            ]);
        });

        $user = User::factory()->create();

        Livewire::actingAs($user)->test('plans')
            ->assertSee('50.00起')
            ->assertDontSee('55.00起');

        $this->assertSame([0, 1], collect($requests)->pluck('current')->all());
        $this->assertSame([20, 20], collect($requests)->pluck('size')->all());
    }

    public function test_admin_can_enable_auto_pricing_on_a_dynamic_plan(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test('admin.plans')
            ->call('create')
            ->set('name', '自动定价套餐')
            ->set('type', 'dedicated_isp')
            ->set('transfer_enable_gb', '50')
            ->set('prices.month', '55')
            ->set('dynamicMode', true)
            ->set('dynamicIspType', '2')
            ->set('autoPricingEnabled', true)
            ->set('autoPricingRelay', '15')
            ->set('autoPricingProfit', '25')
            ->set('autoPricingRound', '5')
            ->set('autoPricingMonthlyAdjustment', '-5')
            ->call('save');

        $plan = Plan::where('name', '自动定价套餐')->firstOrFail();
        $this->assertSame([
            'enabled' => true, 'relay' => 15, 'profit' => 25, 'round' => 5, 'monthly_adjustment' => -5,
            'discounts' => ['quarter' => 5, 'half_year' => 8, 'year' => 12],
        ], $plan->resource_profile['auto_pricing']);
        $this->assertTrue($plan->autoPricing()['enabled']);
        $this->assertSame(-5, $plan->autoPricing()['monthly_adjustment']);
    }

    public function test_buying_a_dynamic_plan_without_selecting_a_line_is_rejected(): void
    {
        $plan = $this->makeDynamicPlan();
        $user = User::factory()->create();

        $this->expectException(ValidationException::class);

        app(OrderService::class)->createOrder($user, $plan, 'month');
    }

    public function test_paying_for_a_dynamically_selected_line_provisions_using_the_customers_pick(): void
    {
        $this->configureOnip();
        $plan = $this->makeDynamicPlan();
        $user = User::factory()->create();

        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);
            $this->assertSame('1481726073313038336', $body['lineId']);

            return Http::response([
                'success' => true, 'code' => 'SUCCESS', 'data' => [
                    'orderNo' => 'ORD1',
                    'instances' => [[
                        'proxyId' => 'SI001', 'ip' => '203.0.113.9', 'port' => 8080,
                        'username' => 'user1', 'password' => 'pass1',
                    ]],
                ],
            ]);
        });

        app(OnipProvisioningService::class)->cacheLine([
            'id' => '1481726073313038336', 'countryCode' => 'USA', 'cityCode' => 'USANYSNYC', 'price' => 11, 'currency' => 'CNY',
        ]);

        $order = app(OrderService::class)->createOrder($user, $plan, 'month', null, [
            'provider' => 'onip',
            'lineId' => '1481726073313038336',
            'line_snapshot' => ['countryCode' => 'USA', 'cityCode' => 'USANYSNYC'],
        ]);
        app(OrderService::class)->markPaid($order);

        $instance = SubscriptionInstance::first();
        $this->assertSame(SubscriptionInstance::STATUS_ACTIVE, $instance->status);
        $this->assertSame('203.0.113.9', $instance->meta['ip']);
        $this->assertSame('1481726073313038336', $instance->meta['lineId']);
    }

    public function test_renewing_an_expired_dynamic_instance_reuses_the_same_line_the_customer_originally_picked(): void
    {
        $this->configureOnip();
        $plan = $this->makeDynamicPlan();
        $user = User::factory()->create();

        $seenLineIds = [];
        Http::fake(function ($request) use (&$seenLineIds) {
            $body = json_decode($request->body(), true);
            $seenLineIds[] = $body['lineId'];

            return Http::response([
                'success' => true, 'code' => 'SUCCESS', 'data' => [
                    'orderNo' => 'ORD-'.count($seenLineIds),
                    'instances' => [[
                        'proxyId' => 'SI'.count($seenLineIds), 'ip' => '203.0.113.'.count($seenLineIds), 'port' => 8080,
                        'username' => 'user1', 'password' => 'pass1',
                    ]],
                ],
            ]);
        });

        app(OnipProvisioningService::class)->cacheLine([
            'id' => 'LINE-A', 'countryCode' => 'USA', 'price' => 11, 'currency' => 'CNY',
        ]);

        $orderService = app(OrderService::class);
        $buyOrder = $orderService->createOrder($user, $plan, 'month', null, [
            'provider' => 'onip', 'lineId' => 'LINE-A', 'line_snapshot' => ['countryCode' => 'USA'],
        ]);
        $orderService->markPaid($buyOrder);

        $instance = SubscriptionInstance::first();
        $originalIp = $instance->meta['ip'];
        $instance->update(['expired_at' => now()->subDay()]);

        // Renewal never asks the customer to pick again — no selected_resource_profile.
        $renewOrder = $orderService->createRenewalOrder($user, $instance, 'month');
        $this->assertNull($renewOrder->selected_resource_profile);
        $orderService->markPaid($renewOrder);

        $instance->refresh();
        $this->assertNotSame($originalIp, $instance->meta['ip']);
        $this->assertSame(SubscriptionInstance::STATUS_ACTIVE, $instance->status);
        $this->assertSame(['LINE-A', 'LINE-A'], $seenLineIds);
    }
}
