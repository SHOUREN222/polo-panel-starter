<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenancyInitialized;
use Tests\TestCase;

/**
 * Tenancy is initialized on the `web` group, and payment callbacks arrive on
 * the `api` group — so GatewayPaymentController resolves the 分站 from the
 * webhook's host itself and runs the handler in that context. Nothing else
 * makes that route tenant-aware, and if it regressed the failure would be
 * quiet and expensive: every site numbers its payments and orders from 1, so
 * a 分站 callback would verify against the platform's payment row and then
 * look for the order in the platform's database, where it does not exist.
 * The customer's money goes through and the order sits 待支付 forever.
 *
 * Platform infrastructure endpoints must stay central — node config and
 * traffic reports serve every site at once. Customer API routes use their
 * own tenant-aware group and are covered by TenantApiIsolationTest.
 */
class PaymentCallbackTenancyTest extends TestCase
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

    private function tenantWithPayment(string $id): Tenant
    {
        $t = Tenant::create(['id' => $id]);
        $t->domains()->create(['domain' => $id]);
        $t->run(fn () => Payment::create([
            'name' => '易支付', 'gateway' => Payment::GATEWAY_EPAY_ALIPAY, 'enable' => true, 'config' => [],
        ]));

        return $t;
    }

    public function test_a_callback_on_a_subdomain_runs_as_that_site(): void
    {
        $this->tenantWithPayment('payshop');

        $initialized = null;
        Event::listen(TenancyInitialized::class, function ($e) use (&$initialized) {
            $initialized = $e->tenancy->tenant->getTenantKey();
        });

        $this->post('http://payshop.example.test/api/payment/1/notify');

        $this->assertSame('payshop', $initialized, 'the callback ran as the wrong site');
    }

    public function test_the_platforms_own_callback_still_works(): void
    {
        Payment::create([
            'name' => '易支付', 'gateway' => Payment::GATEWAY_EPAY_ALIPAY, 'enable' => true, 'config' => [],
        ]);

        $initialized = null;
        Event::listen(TenancyInitialized::class, function ($e) use (&$initialized) {
            $initialized = $e->tenancy->tenant->getTenantKey();
        });

        $response = $this->post('http://app.example.test/api/payment/1/notify');

        $this->assertNull($initialized, 'a 主站 callback must not be scoped to a tenant');
        $this->assertNotSame(404, $response->status());
    }

    public function test_node_endpoints_stay_central_on_a_subdomain(): void
    {
        // A node's config/user list covers every site at once. Scoping it to one
        // would drop every other site's customers off that node.
        $this->tenantWithPayment('nodeshop');

        $initialized = null;
        Event::listen(TenancyInitialized::class, function ($e) use (&$initialized) {
            $initialized = $e->tenancy->tenant->getTenantKey();
        });

        $this->get('http://nodeshop.example.test/api/nodes/config');

        $this->assertNull($initialized, 'node endpoints must not be tenant-scoped');
    }
}
