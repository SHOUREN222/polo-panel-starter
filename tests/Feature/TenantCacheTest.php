<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Every Cache:: call inside a 分站 used to throw "This cache store does not
 * support tagging", because stancl's CacheTenancyBootstrapper isolates tenants
 * by wrapping each call in ->tags() and neither the file nor the database
 * driver supports tags. It went unnoticed from the day multi-tenancy shipped:
 * the four call sites are an hourly alert (try/caught), two onip helpers, and
 * the 套餐 page — and no test had ever rendered that page as a 分站 customer,
 * so nothing exercised the one path a customer hits on their way to paying.
 *
 * The bootstrapper is off now and keys are scoped explicitly instead.
 */
class TenantCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Guard the reason this bug survived: on the array store, which
        // supports tags, every assertion below passes even with the broken
        // bootstrapper on. The suite runs on `file` now (see TestCase), and
        // this test is only meaningful there.
        $this->assertFalse(
            Cache::getStore() instanceof \Illuminate\Cache\TaggableStore,
            'These tests only reproduce production on a non-taggable store.'
        );
    }

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

    public function test_the_cache_works_inside_a_tenant(): void
    {
        $t = Tenant::create(['id' => 'cacheshop']);

        $t->run(function () {
            Cache::put('probe', 'v', 60);
            $this->assertSame('v', Cache::get('probe'));
            $this->assertTrue(Cache::has('probe'));
            $this->assertFalse(Cache::add('probe', 'again', 60)); // the alert cooldown's primitive
            Cache::forget('probe');
            $this->assertNull(Cache::get('probe'));
        });
    }

    public function test_a_tenant_customer_can_load_the_plans_page_with_an_isp_plan(): void
    {
        // The plan that trips it: auto-priced dynamic ISP, whose "from" price
        // goes through Cache::remember to avoid an onip call per pageview.
        Plan::create([
            'name' => '静态ISP', 'type' => Plan::TYPE_DEDICATED_ISP, 'sell' => true,
            'prices' => ['month' => 1500],
            'resource_profile' => [
                'provider' => 'onip_dynamic',           // required, or fromPrice() never reaches the cache
                'ispType' => 2,
                'auto_pricing' => ['enabled' => true],
            ],
        ]);

        $t = Tenant::create(['id' => 'plansshop']);
        $customer = $t->run(fn () => User::create([
            'name' => 'c', 'email' => 'c@example.com', 'password' => 'x',
        ]));

        tenancy()->initialize($t);
        Livewire::actingAs($customer)->test('plans')->assertOk();
        tenancy()->end();
    }
}
