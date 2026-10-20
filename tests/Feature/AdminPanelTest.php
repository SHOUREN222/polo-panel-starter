<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\Setting;
use App\Models\SubscriptionInstance;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private function adminPath(string $suffix = ''): string
    {
        return '/'.config('admin.path').$suffix;
    }

    public function test_guest_is_redirected_to_admin_login(): void
    {
        $this->get($this->adminPath('/plans'))->assertRedirect($this->adminPath('/login'));
    }

    public function test_non_admin_user_is_forbidden(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get($this->adminPath('/plans'))->assertForbidden();
        $this->actingAs($user)->get($this->adminPath('/servers'))->assertForbidden();
        $this->actingAs($user)->get($this->adminPath('/orders'))->assertForbidden();
        $this->actingAs($user)->get($this->adminPath('/users'))->assertForbidden();
    }

    public function test_admin_can_view_admin_pages(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get($this->adminPath('/plans'))->assertOk();
        $this->actingAs($admin)->get($this->adminPath('/servers'))->assertOk();
        $this->actingAs($admin)->get($this->adminPath('/orders'))->assertOk();
        $this->actingAs($admin)->get($this->adminPath('/users'))->assertOk();
    }

    public function test_admin_login_page_rejects_non_admin_account(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        Livewire::test('admin.login')
            ->set('email', $user->email)
            ->set('password', 'password123')
            ->call('login')
            ->assertSet('error', '该账号不是管理员，无法登录后台');

        $this->assertGuest();
    }

    public function test_admin_login_page_accepts_admin_account(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'password' => 'password123']);

        Livewire::test('admin.login')
            ->set('email', $admin->email)
            ->set('password', 'password123')
            ->call('login');

        $this->assertAuthenticatedAs($admin);
    }

    public function test_admin_can_create_edit_and_delete_a_plan(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        Livewire::test('admin.plans')
            ->call('create')
            ->set('name', '测试套餐')
            ->set('transfer_enable_gb', '10')
            ->set('prices.month', '9.99')
            ->call('save')
            ->assertSet('showForm', false);

        $plan = Plan::where('name', '测试套餐')->firstOrFail();
        $this->assertSame(10737418240, $plan->transfer_enable);
        $this->assertSame(999, $plan->prices['month']);

        Livewire::test('admin.plans')
            ->call('edit', $plan->id)
            ->set('name', '改名套餐')
            ->call('save');

        $this->assertSame('改名套餐', $plan->fresh()->name);

        Livewire::test('admin.plans')->call('delete', $plan->id);
        $this->assertNull(Plan::find($plan->id));
    }

    public function test_admin_can_set_tags_speed_limit_capacity_and_reset_mode(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        Livewire::test('admin.plans')
            ->call('create')
            ->set('name', '测试套餐')
            ->set('transfer_enable_gb', '10')
            ->set('prices.month', '9.99')
            ->set('speed_limit', '50')
            ->set('capacity_limit', '100')
            ->set('traffic_reset_mode', 'anniversary')
            ->set('tagInput', '热门')
            ->call('addTag')
            ->set('tagInput', '促销')
            ->call('addTag')
            ->call('save');

        $plan = Plan::where('name', '测试套餐')->firstOrFail();
        $this->assertSame(50, $plan->speed_limit);
        $this->assertSame(100, $plan->capacity_limit);
        $this->assertSame('anniversary', $plan->traffic_reset_mode);
        $this->assertSame(['热门', '促销'], $plan->tags);
    }

    public function test_admin_can_toggle_show_sell_and_renewal_independently_from_the_list(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        $plan = Plan::create([
            'name' => 'x', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 1, 'prices' => ['month' => 1000], 'sell' => true,
        ]);
        $this->assertTrue($plan->show);
        $this->assertTrue($plan->allow_renewal);

        $component = Livewire::test('admin.plans');
        $component->call('toggleShow', $plan->id);
        $this->assertFalse($plan->fresh()->show);

        $component->call('toggleSell', $plan->id);
        $this->assertFalse($plan->fresh()->sell);

        $component->call('toggleRenewal', $plan->id);
        $this->assertFalse($plan->fresh()->allow_renewal);
    }

    public function test_force_update_existing_subscribers_pushes_new_transfer_enable_to_active_instances(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        $plan = Plan::create([
            'name' => '静态ISP', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 10 * 1024 * 1024 * 1024, 'prices' => ['month' => 5000],
            'resource_profile' => ['provider' => 'stub'], 'sell' => true,
        ]);
        $instance = SubscriptionInstance::create([
            'user_id' => User::factory()->create()->id, 'plan_id' => $plan->id,
            'transfer_enable' => 10 * 1024 * 1024 * 1024, 'status' => 'active', 'expired_at' => now()->addMonth(),
        ]);

        Livewire::test('admin.plans')
            ->call('edit', $plan->id)
            ->set('transfer_enable_gb', '50')
            ->set('forceUpdateExisting', true)
            ->call('save');

        $this->assertSame(50 * 1024 * 1024 * 1024, $instance->fresh()->transfer_enable);
    }

    public function test_admin_can_search_real_onip_lines_and_select_one_into_the_plan(): void
    {
        Setting::set('onip_app_id', 'id');
        Setting::set('onip_app_secret', 'secret');

        Http::fake(fn () => Http::response([
            'success' => true, 'code' => 'SUCCESS', 'data' => [
                'total' => 1,
                'records' => [[
                    'id' => '1481726072834887680', 'countryCode' => 'USA', 'cityCode' => 'USANYSNYC',
                    'businessTypeCode' => 'other', 'ispType' => 2, 'price' => 11, 'currency' => 'CNY',
                    'quantity' => 2277, 'active' => true, 'status' => 0,
                ]],
            ],
        ]));

        $admin = User::factory()->create(['is_admin' => true]);

        $component = Livewire::actingAs($admin)->test('admin.plans')
            ->call('create')
            ->set('type', 'dedicated_isp')
            ->set('lineSearchCountry', 'USA')
            ->call('searchOnipLines');

        $component->assertSet('lineSearchResults.0.id', '1481726072834887680');

        $component->call('selectOnipLine', '1481726072834887680')
            ->assertSet('resource_line_id', '1481726072834887680')
            ->assertSet('resource_line_snapshot.businessTypeCode', 'other');

        $component->set('name', '真实静态ISP套餐')
            ->set('transfer_enable_gb', '10')
            ->set('prices.month', '55')
            ->call('save');

        $plan = Plan::where('name', '真实静态ISP套餐')->firstOrFail();
        $this->assertSame('1481726072834887680', $plan->resource_profile['lineId']);
        $this->assertSame('onip', $plan->resource_profile['provider']);
        $this->assertSame('other', $plan->resource_profile['line_snapshot']['businessTypeCode']);
    }

    public function test_onip_line_search_shows_a_clear_error_when_not_configured(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test('admin.plans')
            ->call('create')
            ->set('type', 'dedicated_isp')
            ->call('searchOnipLines')
            ->assertSet('lineSearchResults', [])
            ->assertSee('还没配置 onip.cc 密钥');
    }

    public function test_editing_a_plan_with_a_real_line_reloads_the_snapshot_without_a_new_api_call(): void
    {
        Http::fake();

        $plan = Plan::create([
            'name' => '已配置真实线路的套餐', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1, 'prices' => ['month' => 5500],
            'resource_profile' => [
                'provider' => 'onip', 'lineId' => '999',
                'line_snapshot' => ['countryCode' => 'USA', 'cityCode' => 'USANYSNYC', 'businessTypeCode' => 'other', 'price' => 11],
            ],
            'sell' => true,
        ]);

        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test('admin.plans')
            ->call('edit', $plan->id)
            ->assertSet('resource_line_id', '999')
            ->assertSet('resource_line_snapshot.cityCode', 'USANYSNYC')
            ->assertSee('已选择真实线路');

        Http::assertNothingSent();
    }

    public function test_clearing_selected_line_falls_back_to_stub_fields_on_save(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test('admin.plans')
            ->call('create')
            ->set('type', 'dedicated_isp')
            ->set('resource_line_id', '999')
            ->set('resource_line_snapshot', ['cityCode' => 'X'])
            ->call('clearSelectedLine')
            ->assertSet('resource_line_id', '')
            ->set('resource_provider', 'stub')
            ->set('name', '本地模拟套餐')
            ->set('transfer_enable_gb', '10')
            ->set('prices.month', '10')
            ->call('save');

        $plan = Plan::where('name', '本地模拟套餐')->firstOrFail();
        $this->assertArrayNotHasKey('lineId', $plan->resource_profile);
        $this->assertSame('stub', $plan->resource_profile['provider']);
    }

    public function test_admin_cannot_delete_a_plan_with_existing_orders(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $buyer = User::factory()->create();

        $plan = Plan::create([
            'name' => '有订单的套餐', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 1, 'prices' => ['month' => 1000], 'sell' => true,
        ]);

        app(OrderService::class)->createOrder($buyer, $plan, 'month');

        Livewire::actingAs($admin)
            ->test('admin.plans')
            ->call('delete', $plan->id)
            ->assertSet('error', fn ($value) => str_contains($value, '无法删除'));

        $this->assertNotNull(Plan::find($plan->id));
    }

    public function test_admin_can_create_edit_and_delete_a_server(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        Livewire::test('admin.servers')
            ->call('create')
            ->set('name', '测试节点')
            ->set('host', '203.0.113.10')
            ->set('port', 8443)
            ->set('group_id', 1)
            ->call('save');

        $server = Server::where('name', '测试节点')->firstOrFail();
        $this->assertSame('203.0.113.10', $server->host);

        Livewire::test('admin.servers')
            ->call('delete', $server->id);

        $this->assertNull(Server::find($server->id));
    }

    public function test_admin_can_create_a_vless_reality_server_with_config(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        Livewire::test('admin.servers')
            ->call('create')
            ->set('name', 'Reality节点')
            ->set('host', '203.0.113.20')
            ->set('port', 8444)
            ->set('group_id', 1)
            ->set('protocol', 'vless')
            ->set('reality_public_key', 'HnJbfJ8m63KmktR7x_i8aCmSX7F1pXnKma7pzwTY7jc')
            ->set('reality_short_id', '55307c8fc36f29c0')
            ->set('reality_sni', 'addons.mozilla.org')
            ->call('save');

        $server = Server::where('name', 'Reality节点')->firstOrFail();
        $this->assertSame('vless', $server->protocol);
        $this->assertSame('HnJbfJ8m63KmktR7x_i8aCmSX7F1pXnKma7pzwTY7jc', $server->config['public_key']);
        $this->assertSame('55307c8fc36f29c0', $server->config['short_id']);
        $this->assertSame('addons.mozilla.org', $server->config['sni']);
        $this->assertSame('xtls-rprx-vision', $server->config['flow']);
    }

    public function test_admin_cannot_save_vless_server_without_reality_fields(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        Livewire::test('admin.servers')
            ->call('create')
            ->set('name', 'Reality节点')
            ->set('host', '203.0.113.20')
            ->set('port', 8444)
            ->set('group_id', 1)
            ->set('protocol', 'vless')
            ->call('save')
            ->assertHasErrors(['reality_public_key', 'reality_short_id', 'reality_sni']);

        $this->assertNull(Server::where('name', 'Reality节点')->first());
    }

    public function test_admin_can_ban_and_cannot_ban_self(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $target = User::factory()->create();
        $target->createToken('phone');
        DB::table('sessions')->insert([
            'id' => 'target-session',
            'user_id' => $target->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => '{}',
            'last_activity' => now()->timestamp,
        ]);

        $component = Livewire::actingAs($admin)->test('admin.users');

        $component->call('toggleBan', $admin->id)
            ->assertSet('error', '不能封禁自己');
        $this->assertFalse($admin->fresh()->banned);

        $component->call('toggleBan', $target->id);
        $this->assertTrue($target->fresh()->banned);
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $target->id]);
    }

    public function test_banned_user_cannot_log_in(): void
    {
        $user = User::factory()->create(['banned' => true, 'password' => 'password123']);

        Livewire::test('auth.login')
            ->set('email', $user->email)
            ->set('password', 'password123')
            ->call('login')
            ->assertSet('error', '账号已被封禁');

        $this->assertGuest();
    }

    public function test_admin_orders_page_lists_orders_with_status_filter(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $buyer = User::factory()->create();

        $plan = Plan::create([
            'name' => 'x', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 1, 'prices' => ['month' => 1000], 'sell' => true,
        ]);

        $order = app(OrderService::class)->createOrder($buyer, $plan, 'month');

        Livewire::actingAs($admin)
            ->test('admin.orders')
            ->assertSee($order->trade_no)
            ->set('statusFilter', Order::STATUS_PAID)
            ->assertDontSee($order->trade_no);
    }
}
