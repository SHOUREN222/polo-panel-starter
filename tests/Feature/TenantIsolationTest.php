<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\RoutingRule;
use App\Models\ServerGroup;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Support\SiteUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // Always leave tenant context + restore the central connection first,
        // otherwise the default connection stays pointed at a tenant DB and
        // corrupts the next test's RefreshDatabase.
        if (tenant()) {
            tenancy()->end();
        }

        foreach (Tenant::all() as $t) {
            $t->delete(); // drops the tenant SQLite file
        }

        parent::tearDown();
    }

    public function test_each_tenant_has_its_own_isolated_users_database(): void
    {
        $a = Tenant::create(['id' => 'iso-a']);
        $b = Tenant::create(['id' => 'iso-b']);

        // Same email in two different 分站 = two different people (per-site namespace).
        tenancy()->initialize($a);
        User::create(['name' => '甲', 'email' => 'dup@example.com', 'password' => 'x']);
        $this->assertSame(1, User::count());
        tenancy()->end();

        tenancy()->initialize($b);
        User::create(['name' => '乙', 'email' => 'dup@example.com', 'password' => 'x']); // must NOT collide with A
        $this->assertSame(1, User::count());
        $this->assertSame('乙', User::first()->name);
        tenancy()->end();

        // 分站A still sees only its own user — B's data is invisible.
        tenancy()->initialize($a);
        $this->assertSame(1, User::count());
        $this->assertSame('甲', User::first()->name);
        tenancy()->end();
    }

    public function test_tenant_data_is_invisible_to_the_central_main_site(): void
    {
        $t = Tenant::create(['id' => 'iso-c']);

        tenancy()->initialize($t);
        User::create(['name' => '分站客户', 'email' => 'tenant-only@example.com', 'password' => 'x']);
        tenancy()->end();

        // Back on central: the tenant's user does not exist here.
        $this->assertFalse(User::where('email', 'tenant-only@example.com')->exists());
    }

    public function test_a_tenant_order_lives_in_the_tenant_db_but_reads_the_shared_plan_from_central(): void
    {
        // Shared catalog: a plan created in central (Plan uses CentralConnection).
        $plan = Plan::create([
            'name' => '共享套餐', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 1024, 'prices' => ['month' => 1500], 'sell' => true,
        ]);

        $t = Tenant::create(['id' => 'iso-order']);

        tenancy()->initialize($t);
        $user = User::create(['name' => '分站客户', 'email' => 'buyer@example.com', 'password' => 'x']);
        $order = Order::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'type' => Order::TYPE_NEW,
            'period' => 'month', 'trade_no' => 'T-ISO-1', 'total_amount' => 1500, 'status' => Order::STATUS_PENDING,
        ]);

        // The order is in the tenant DB; its plan resolves across to central.
        $this->assertSame(1, Order::count());
        $this->assertSame('共享套餐', $order->plan->name);
        tenancy()->end();

        // Central sees the plan (shared) but NOT the tenant's order.
        $this->assertSame(1, Plan::count());
        $this->assertSame(0, Order::count());
    }

    public function test_the_removed_tenant_probe_is_not_reachable_on_any_host(): void
    {
        $t = Tenant::create(['id' => 'daili1']);
        $t->domains()->create(['domain' => 'daili1']); // subdomain label

        // Seed one user inside 分站 daili1.
        tenancy()->initialize($t);
        User::create(['name' => '分站客户', 'email' => 'a@example.com', 'password' => 'x']);
        tenancy()->end();

        $this->get('http://daili1.example.test/__tenant-probe')->assertNotFound();
        $this->get('http://app.example.test/__tenant-probe')->assertNotFound();
    }

    public function test_customer_routes_are_served_on_both_a_subdomain_and_central(): void
    {
        $t = Tenant::create(['id' => 'daili2']);
        $t->domains()->create(['domain' => 'daili2']);

        // The SAME customer route renders on a 分站 subdomain (tenant context) …
        $this->get('http://daili2.example.test/login')->assertOk()->assertSee('登录');

        // … and still on the central 主站 (universal route continues-as-central).
        $this->get('http://app.example.test/login')->assertOk()->assertSee('登录');
    }

    public function test_a_subdomain_dashboard_shows_the_tenant_users_own_data(): void
    {
        $t = Tenant::create(['id' => 'daili3']);
        $t->domains()->create(['domain' => 'daili3']);

        tenancy()->initialize($t);
        $user = User::create(['name' => '分站甲', 'email' => 'me@example.com', 'password' => 'x']);
        tenancy()->end();

        // Authenticated request to the subdomain dashboard runs in daili3's context.
        $resp = $this->actingAs($user)->get('http://daili3.example.test/');
        $resp->assertOk()->assertSee('分站甲');
    }

    public function test_payment_gateways_are_per_tenant(): void
    {
        // 主站 gateway
        Payment::create(['name' => '主站支付宝', 'gateway' => 'epay_alipay', 'enable' => true]);

        $t = Tenant::create(['id' => 'payshop']);
        tenancy()->initialize($t);
        $this->assertSame(0, Payment::count()); // 分站 starts with its own (empty) gateways
        Payment::create(['name' => '代理支付宝', 'gateway' => 'epay_alipay', 'enable' => true]);
        $this->assertSame(1, Payment::count());
        tenancy()->end();

        // 主站 still sees only its own gateway.
        $this->assertSame(1, Payment::count());
        $this->assertSame('主站支付宝', Payment::first()->name);
    }

    public function test_a_tenant_brands_its_own_site_without_touching_the_main_site(): void
    {
        Setting::set('site_name', '主站官方'); // central branding

        $t = Tenant::create(['id' => 'brandshop']);
        $t->domains()->create(['domain' => 'brandshop']);

        // 分站 sets its own name (writes a TenantSetting override, not central).
        tenancy()->initialize($t);
        Setting::set('site_name', '代理小铺');
        $this->assertSame('代理小铺', Setting::get('site_name'));
        tenancy()->end();

        // Central 主站 keeps its own name — the override did not leak.
        $this->assertSame('主站官方', Setting::get('site_name'));

        // The 主站 login still shows the platform name …
        $this->get('http://app.example.test/login')
            ->assertOk()->assertSee('主站官方');

        // (each HTTP request is its own php-fpm process in prod; end the
        //  leftover in-process tenancy so this test's next request starts clean.)
        if (tenant()) {
            tenancy()->end();
        }

        // … and the 分站's own pages render ITS branded name instead.
        $this->get('http://brandshop.example.test/login')
            ->assertOk()->assertSee('代理小铺')->assertDontSee('主站官方');
    }

    public function test_subdomain_pages_load_the_shared_global_vite_assets_not_a_tenant_asset_route(): void
    {
        // The Vite build (css/js) is one shared bundle served by nginx from
        // public/build for every site. stancl's asset_helper_tenancy would
        // rewrite @vite's asset() URLs to /tenancy/assets/build/... — a route
        // that 500s here — leaving the 分站 completely unstyled. Guard against
        // that regressing: the subdomain must reference the plain /build/ path.
        $t = Tenant::create(['id' => 'assetshop']);
        $t->domains()->create(['domain' => 'assetshop']);

        $html = $this->get('http://assetshop.example.test/login')->assertOk()->getContent();

        $this->assertStringContainsString('/build/', $html);          // global asset path
        $this->assertStringNotContainsString('/tenancy/assets/', $html); // NOT the broken tenant route
    }

    public function test_a_tenant_without_a_branding_override_inherits_the_platform_name(): void
    {
        Setting::set('site_name', '平台默认名');

        $t = Tenant::create(['id' => 'plainshop']);
        $t->domains()->create(['domain' => 'plainshop']);

        // No override set for this 分站 → falls back to the central value.
        $this->get('http://plainshop.example.test/login')
            ->assertOk()->assertSee('平台默认名');
    }

    public function test_every_customer_page_renders_on_a_subdomain(): void
    {
        $t = Tenant::create(['id' => 'daili4']);
        $t->domains()->create(['domain' => 'daili4']);

        tenancy()->initialize($t);
        $user = User::create(['name' => '客户', 'email' => 'c@example.com', 'password' => 'x']);
        $user->forceFill(['is_agent' => true, 'agent_discount' => 10])->save(); // is_agent isn't mass-assignable
        tenancy()->end();

        $base = 'http://daili4.example.test';
        foreach (['/', '/plans', '/renew', '/orders', '/invite', '/knowledge', '/tickets', '/profile', '/traffic', '/agent'] as $path) {
            $r = $this->actingAs($user)->get($base.$path);
            $this->assertSame(200, $r->status(), "分站页 {$path} 应渲染，实际 {$r->status()}");
        }
    }

    public function test_a_tenant_never_inherits_the_platforms_contact_details(): void
    {
        // Cosmetic branding may fall back to the platform's value, but contact
        // routes must not: an agent who hasn't filled these in would otherwise
        // be sending their own customers to the platform's inbox and Telegram
        // group — revealing the reseller setup and mixing shops' users.
        Setting::set('telegram_group_url', 'https://t.me/platform_group');
        Setting::set('support_email', 'support@platform.com');
        Setting::set('site_name', '平台官方');

        $t = Tenant::create(['id' => 'nocontact']);
        $t->run(function () {
            $this->assertNull(Setting::get('telegram_group_url'), '不得继承平台的群');
            $this->assertNull(Setting::get('support_email'), '不得继承平台的客服邮箱');
            // …but a cosmetic key still inherits, so a new 分站 isn't nameless.
            $this->assertSame('平台官方', Setting::get('site_name'));
        });

        // 主站 itself is unaffected.
        $this->assertSame('https://t.me/platform_group', Setting::get('telegram_group_url'));
    }

    public function test_a_tenant_shows_its_own_group_once_set(): void
    {
        Setting::set('telegram_group_url', 'https://t.me/platform_group');

        $t = Tenant::create(['id' => 'owngroup']);
        $t->run(function () {
            Setting::set('telegram_group_url', 'https://t.me/agent_group');
            $this->assertSame('https://t.me/agent_group', Setting::get('telegram_group_url'));
        });

        $this->assertSame('https://t.me/platform_group', Setting::get('telegram_group_url'));
    }

    public function test_mail_is_signed_with_the_tenants_own_name(): void
    {
        // Delivery stays on the platform's SMTP on purpose (one warmed-up,
        // authenticated domain), but the sender NAME must be the shop's — it's
        // what a recipient actually reads. AppServiceProvider sets it from
        // site_name at boot, which happens before tenancy is initialized, so it
        // has to be re-applied once a tenant is in scope.
        Setting::set('site_name', '平台官方');

        $t = Tenant::create(['id' => 'mailname']);
        $t->run(fn () => Setting::set('site_name', '代理小铺'));

        $inTenant = null;
        $t->run(function () use (&$inTenant) {
            $inTenant = config('mail.from.name');
        });

        $this->assertSame('代理小铺', $inTenant);
    }

    public function test_email_links_point_at_the_tenants_own_site(): void
    {
        // The {{url}} token in email templates used to come from the central
        // site_url (or url('/'), which in a queued job is APP_URL) — either way
        // an agent's customer clicking through landed on the platform's site.
        Setting::set('site_url', 'https://app.example.test');

        $t = Tenant::create(['id' => 'linkshop']);
        $t->domains()->create(['domain' => 'linkshop']);

        $inTenant = $t->run(fn () => SiteUrl::current());

        $this->assertSame('https://linkshop.example.test', $inTenant);
        // 主站 keeps its configured address.
        $this->assertSame('https://app.example.test', SiteUrl::current());
    }

    public function test_site_url_is_correct_without_a_request_context(): void
    {
        // Queued mail and console commands have no request to read a host from,
        // which is exactly where the old url('/') fallback went wrong.
        $t = Tenant::create(['id' => 'queueshop']);
        $t->domains()->create(['domain' => 'queueshop']);

        $this->assertSame(
            'https://queueshop.example.test',
            $t->run(fn () => SiteUrl::current())
        );
    }

    public function test_shared_infrastructure_models_read_central_from_within_a_tenant(): void
    {
        // Plans and Servers are platform-shared, so the things that describe
        // them must be too. These tables don't exist in a tenant database, so
        // an unpinned model doesn't read the wrong rows — it 500s with "no such
        // table" the moment any customer-facing page touches it. Nothing does
        // today, which is exactly why it would go unnoticed until it didn't.
        $group = ServerGroup::create(['name' => '默认分组']);
        $rule = RoutingRule::create([
            'name' => '拦 BT', 'pattern' => 'bittorrent', 'action' => 'block', 'enabled' => true,
        ]);

        $t = Tenant::create(['id' => 'infra2']);
        $t->run(function () use ($group, $rule) {
            $this->assertSame('默认分组', ServerGroup::find($group->id)?->name);
            $this->assertSame('拦 BT', RoutingRule::find($rule->id)?->name);
        });
    }
}
