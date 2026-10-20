<?php

namespace Tests\Feature;

use App\Models\Notice;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\TenantWholesale;
use App\Models\TenantWholesaleSettlement;
use App\Models\User;
use App\Services\NodeRelayService;
use App\Services\WholesaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Stancl\Tenancy\Database\Models\Domain;
use Tests\TestCase;

class TenantAdminTest extends TestCase
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

    public function test_super_admin_creates_a_tenant_with_its_own_admin_in_its_own_db(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);

        Livewire::actingAs($admin)->test('admin.tenants')
            ->set('subdomain', 'shop1')
            ->set('adminName', '代理A')
            ->set('adminEmail', 'agent@example.com')
            ->set('adminPassword', 'secret123')
            ->set('agentDiscount', '25')
            ->call('create')
            ->assertHasNoErrors();

        $tenant = Tenant::find('shop1');
        $this->assertNotNull($tenant);
        $this->assertSame(25, (int) $tenant->agent_discount);
        $this->assertTrue(Domain::where('domain', 'shop1')->exists());

        // The 分站 admin lives in the tenant DB, not central.
        tenancy()->initialize($tenant);
        $this->assertTrue(User::where('email', 'agent@example.com')->where('is_admin', true)->exists());
        tenancy()->end();

        $this->assertFalse(User::where('email', 'agent@example.com')->exists()); // central clean
    }

    public function test_tenant_admin_password_must_be_at_least_eight_characters(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);

        Livewire::actingAs($admin)->test('admin.tenants')
            ->set('subdomain', 'shortpw')
            ->set('adminName', '代理A')
            ->set('adminEmail', 'agent@example.com')
            ->set('adminPassword', '1234567')
            ->set('agentDiscount', '25')
            ->call('create')
            ->assertHasErrors(['adminPassword']);

        $this->assertNull(Tenant::find('shortpw'));
    }

    public function test_super_admin_can_change_a_tenants_wholesale_discount_after_creation(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);
        Tenant::create(['id' => 'discshop', 'agent_discount' => 20])->domains()->create(['domain' => 'discshop']);

        Livewire::actingAs($admin)->test('admin.tenants')
            ->set('discountEdits.discshop', '45')
            ->call('updateDiscount', 'discshop')
            ->assertHasNoErrors();

        $this->assertSame(45, (int) Tenant::find('discshop')->agent_discount);
    }

    public function test_wholesale_discount_out_of_range_is_rejected(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);
        Tenant::create(['id' => 'discshop2', 'agent_discount' => 20])->domains()->create(['domain' => 'discshop2']);

        Livewire::actingAs($admin)->test('admin.tenants')
            ->set('discountEdits.discshop2', '95')
            ->call('updateDiscount', 'discshop2')
            ->assertSet('error', fn ($e) => str_contains($e, '0-90'));

        $this->assertSame(20, (int) Tenant::find('discshop2')->agent_discount);
    }

    public function test_support_admin_cannot_change_wholesale_discount(): void
    {
        $support = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPPORT]);
        Tenant::create(['id' => 'discshop3', 'agent_discount' => 20])->domains()->create(['domain' => 'discshop3']);

        Livewire::actingAs($support)->test('admin.tenants')
            ->set('discountEdits.discshop3', '45')
            ->call('updateDiscount', 'discshop3');

        $this->assertSame(20, (int) Tenant::find('discshop3')->agent_discount);
    }

    public function test_duplicate_subdomain_is_rejected(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);
        Tenant::create(['id' => 'taken'])->domains()->create(['domain' => 'taken']);

        Livewire::actingAs($admin)->test('admin.tenants')
            ->set('subdomain', 'taken')
            ->set('adminName', 'x')->set('adminEmail', 'x@example.com')->set('adminPassword', 'secret123')
            ->call('create')
            ->assertSet('error', fn ($e) => str_contains($e, '已被占用'));
    }

    public function test_tenant_admin_is_scoped_to_its_own_site_not_the_platform(): void
    {
        $t = Tenant::create(['id' => 'adminshop']);
        $t->domains()->create(['domain' => 'adminshop']);
        tenancy()->initialize($t);
        $agent = User::create(['name' => '代理', 'email' => 'a@example.com', 'password' => 'x']);
        $agent->forceFill(['is_admin' => true, 'admin_role' => User::ROLE_SUPER, 'is_owner' => true])->save();
        tenancy()->end();

        $base = 'http://adminshop.example.test/'.config('admin.path');

        // The 分站 admin can manage their own site's sections…
        $this->actingAs($agent)->get("$base/users")->assertOk();
        $this->actingAs($agent)->get("$base/orders")->assertOk();
        $this->actingAs($agent)->get("$base/payments")->assertOk(); // configure own gateway
        $this->actingAs($agent)->get("$base/branding")->assertOk();  // brand own site
        $this->actingAs($agent)->get("$base/wholesale")->assertOk(); // their own 进货 ledger

        // …but platform sections are forbidden, even as a 分站-super admin.
        $this->actingAs($agent)->get("$base/system")->assertForbidden();
        $this->actingAs($agent)->get("$base/servers")->assertForbidden();
        $this->actingAs($agent)->get("$base/tenants")->assertForbidden(); // can't manage 分站

        // Pricing is the platform's, never the agent's — 站长 sets prices and the
        // agent's margin is the wholesale discount. Both of these would let an
        // agent cut the price, and since wholesale settles on order->total_amount
        // a 100%-off coupon would hand out service on the platform's nodes for
        // free. See the note on User::TENANT_ADMIN_ROUTES.
        $this->actingAs($agent)->get("$base/coupons")->assertForbidden();
        $this->actingAs($agent)->get("$base/plans")->assertForbidden();

        // Announcements are platform infrastructure info (node maintenance,
        // service changes) every customer needs regardless of subdomain —
        // there's no per-分站 version to manage. See App\Models\Notice.
        $this->actingAs($agent)->get("$base/notices")->assertForbidden();
    }

    public function test_a_tenants_customers_see_the_platforms_announcements(): void
    {
        Notice::create(['title' => '节点维护通知', 'content' => '本周六凌晨维护', 'show' => true, 'sort' => 1]);

        $t = Tenant::create(['id' => 'noticeshop']);
        $t->domains()->create(['domain' => 'noticeshop']);

        // Notice always reads the central DB, even from inside a tenant —
        // no per-分站 announcements table to fall out of sync.
        tenancy()->initialize($t);
        $this->assertTrue(Notice::where('title', '节点维护通知')->exists());
        tenancy()->end();
    }

    public function test_wholesale_ledger_shows_only_this_tenants_own_entries(): void
    {
        $wholesale = app(WholesaleService::class);

        Tenant::create(['id' => 'ledgershop'])->domains()->create(['domain' => 'ledgershop']);
        Tenant::create(['id' => 'othershop'])->domains()->create(['domain' => 'othershop']);

        $wholesale->credit('ledgershop', 10000, 7000);
        $wholesale->credit('othershop', 99900, 88800); // must never leak into ledgershop's view

        tenancy()->initialize(Tenant::find('ledgershop'));
        $agent = User::create(['name' => '代理', 'email' => 'a@example.com', 'password' => 'x']);
        $agent->forceFill(['is_admin' => true, 'admin_role' => User::ROLE_SUPER, 'is_owner' => true])->save();
        tenancy()->end();

        $base = 'http://ledgershop.example.test/'.config('admin.path');

        $this->actingAs($agent)->get("$base/wholesale")
            ->assertOk()
            ->assertSee('¥100.00') // the face-value credit
            ->assertSee('实收现金 ¥70.00')
            ->assertDontSee('999.00'); // othershop's amounts never show
    }

    public function test_wholesale_ledger_renders_with_settlements_but_no_topups(): void
    {
        // Regression: a settlement-only tenant (real production shape — an
        // old order predating the topup-logging feature, so 0 topups but 1+
        // settlements) crashed with "Call to a member function getKey() on
        // array". Eloquent\Collection::map() only auto-downgrades to a plain
        // Collection when it sees a non-Model item — an EMPTY mapped
        // collection never triggers that check, so it silently stays
        // Eloquent\Collection, and ->merge() with a non-empty argument then
        // calls ->getKey() on that argument's plain-array items. A test with
        // both sides non-empty (or both empty) never hits this asymmetric
        // case — the bug needs exactly one side empty and the other not.
        Tenant::create(['id' => 'settleonly'])->domains()->create(['domain' => 'settleonly']);

        TenantWholesaleSettlement::create([
            'tenant_id' => 'settleonly', 'order_id' => 1, 'trade_no' => 'T1',
            'amount' => 3800, 'status' => TenantWholesaleSettlement::STATUS_COMPLETED,
            'reserved_at' => now(), 'completed_at' => now(),
        ]);

        tenancy()->initialize(Tenant::find('settleonly'));
        $agent = User::create(['name' => '代理', 'email' => 'a@example.com', 'password' => 'x']);
        $agent->forceFill(['is_admin' => true, 'admin_role' => User::ROLE_SUPER, 'is_owner' => true])->save();
        tenancy()->end();

        $base = 'http://settleonly.example.test/'.config('admin.path');

        $this->actingAs($agent)->get("$base/wholesale")
            ->assertOk()
            ->assertSee('T1')
            ->assertSee('¥38.00');
    }

    public function test_wholesale_ledger_is_not_reachable_on_the_main_site(): void
    {
        // 主站 owes nobody wholesale — there's no ledger to show, so this
        // route 404s there instead of rendering an empty, confusing page.
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);

        $this->actingAs($admin)->get('/'.config('admin.path').'/wholesale')->assertNotFound();
    }

    public function test_branding_page_saves_and_validates(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);

        Livewire::actingAs($admin)->test('admin.branding')
            ->set('site_name', '新招牌')
            ->set('theme_primary_color', '#123abc')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('新招牌', Setting::get('site_name'));
        $this->assertSame('#123abc', Setting::get('theme_primary_color'));

        // A malformed color is rejected before anything is written.
        Livewire::actingAs($admin)->test('admin.branding')
            ->set('site_name', 'x')
            ->set('theme_primary_color', 'red')
            ->call('save')
            ->assertHasErrors('theme_primary_color');
    }

    public function test_node_ordinal_is_never_reused_after_a_tenant_is_deleted(): void
    {
        // A reused ordinal would let a new tenant's users collide with a
        // deleted tenant's global node ids (App\Support\NodeUserId).
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);

        Livewire::actingAs($admin)->test('admin.tenants')
            ->set('subdomain', 'churn1')
            ->set('adminName', 'x')->set('adminEmail', 'x1@example.com')->set('adminPassword', 'secret123')
            ->call('create');
        $firstOrdinal = (int) Tenant::find('churn1')->node_ordinal;
        Tenant::find('churn1')->delete();

        Livewire::actingAs($admin)->test('admin.tenants')
            ->set('subdomain', 'churn2')
            ->set('adminName', 'x')->set('adminEmail', 'x2@example.com')->set('adminPassword', 'secret123')
            ->call('create');
        $secondOrdinal = (int) Tenant::find('churn2')->node_ordinal;

        $this->assertGreaterThan($firstOrdinal, $secondOrdinal);
    }

    public function test_recreating_a_deleted_tenant_id_survives_a_leftover_database_file(): void
    {
        // Reproduces a real incident: stancl's own delete cleanup didn't
        // remove the physical SQLite file, so recreating the same id threw
        // TenantDatabaseAlreadyExistsException mid-create — the tenant row
        // landed in the DB with no domain and no migrated tables.
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);

        Tenant::create(['id' => 'restore1'])->delete();

        $stalePath = database_path(config('tenancy.database.prefix').'restore1'.config('tenancy.database.suffix'));
        file_put_contents($stalePath, ''); // simulate the leftover file the real bug left behind
        $this->assertFileExists($stalePath);

        Livewire::actingAs($admin)->test('admin.tenants')
            ->set('subdomain', 'restore1')
            ->set('adminName', 'x')->set('adminEmail', 'x@example.com')->set('adminPassword', 'secret123')
            ->call('create')
            ->assertHasNoErrors();

        $tenant = Tenant::find('restore1');
        $this->assertNotNull($tenant);
        $this->assertTrue(Domain::where('domain', 'restore1')->exists());

        tenancy()->initialize($tenant);
        $this->assertTrue(User::where('email', 'x@example.com')->exists());
        tenancy()->end();
    }

    public function test_subdomain_matching_a_platform_domain_is_rejected(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);

        // app.example.test is the main site itself (config('tenancy.central_domains')).
        Livewire::actingAs($admin)->test('admin.tenants')
            ->set('subdomain', 'app')
            ->set('adminName', 'x')->set('adminEmail', 'x@example.com')->set('adminPassword', 'secret123')
            ->call('create')
            ->assertSet('error', fn ($e) => str_contains($e, '冲突'));

        $this->assertNull(Tenant::find('app'));
    }

    public function test_support_admin_cannot_create_a_tenant(): void
    {
        $support = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPPORT]);

        Livewire::actingAs($support)->test('admin.tenants')
            ->set('subdomain', 'nope')
            ->set('adminName', 'x')->set('adminEmail', 'x@example.com')->set('adminPassword', 'secret123')
            ->call('create');

        $this->assertNull(Tenant::find('nope'));
    }

    public function test_the_delete_confirmation_names_what_would_be_destroyed(): void
    {
        // Dropping a 分站's database is irreversible short of last night's
        // backup, so the confirmation has to say what's actually at stake —
        // a generic "are you sure?" is too easy to click through.
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);

        $t = Tenant::create(['id' => 'busyshop', 'agent_discount' => 20]);
        app(WholesaleService::class)->credit('busyshop', 20000);
        $t->run(function () {
            $agent = User::create(['name' => 'a', 'email' => 'a@example.com', 'password' => 'x']);
            $agent->forceFill(['is_admin' => true])->save();
            User::create(['name' => 'c1', 'email' => 'c1@example.com', 'password' => 'x'])
                ->update(['plan_expired_at' => now()->addMonth()]);
            User::create(['name' => 'c2', 'email' => 'c2@example.com', 'password' => 'x']);
        });

        $html = Livewire::actingAs($admin)->test('admin.tenants')->html();

        $this->assertStringContainsString('2 个客户', $html);   // admins excluded
        $this->assertStringContainsString('1 个有效订阅', $html);
        $this->assertStringContainsString('¥200.00', $html);
        $this->assertStringContainsString('只能从昨夜备份恢复', $html);
    }

    public function test_deleting_a_tenant_clears_its_central_ledger_and_rebuilds_the_relay(): void
    {
        $this->mock(NodeRelayService::class, function ($mock) {
            $mock->shouldReceive('sync')->once()->andReturn([
                'pushed' => false, 'instances' => 0, 'message' => 'not configured',
            ]);
        });

        $tenant = Tenant::create(['id' => 'delete-clean']);
        app(WholesaleService::class)->credit('delete-clean', 9000);
        TenantWholesaleSettlement::create([
            'tenant_id' => 'delete-clean',
            'order_id' => 1,
            'trade_no' => 'OLD-TENANT-ORDER',
            'amount' => 1500,
            'status' => TenantWholesaleSettlement::STATUS_COMPLETED,
            'reserved_at' => now(),
            'completed_at' => now(),
        ]);

        $tenant->delete();

        $this->assertFalse(TenantWholesale::where('tenant_id', 'delete-clean')->exists());
        $this->assertFalse(TenantWholesaleSettlement::where('tenant_id', 'delete-clean')->exists());

        // Reusing the subdomain starts with a genuinely empty ledger instead
        // of inheriting balance or colliding with order id 1 from the old site.
        Tenant::create(['id' => 'delete-clean']);
        $this->assertSame(0, TenantWholesaleSettlement::where('tenant_id', 'delete-clean')->count());
        $this->assertSame(0, app(WholesaleService::class)->balance('delete-clean'));

        // tearDown deletes the recreated tenant; only the deletion under test
        // belongs to the one-call relay assertion above.
        $this->app->forgetInstance(NodeRelayService::class);
    }
}
