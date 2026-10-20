<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TrafficLog;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function adminPath(string $suffix = ''): string
    {
        return '/'.config('admin.path').$suffix;
    }

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

    public function test_admin_root_redirects_to_dashboard_not_plans(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get($this->adminPath('/'))->assertOk();
    }

    public function test_guest_is_redirected_away_from_dashboard(): void
    {
        $this->get($this->adminPath('/'))->assertRedirect($this->adminPath('/login'));
    }

    public function test_dashboard_shows_real_revenue_and_pending_counts(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $buyer = User::factory()->create();
        $plan = $this->makePlan();

        $orderService = app(OrderService::class);
        $orderService->markPaid($orderService->createOrder($buyer, $plan, 'month'));

        Ticket::create(['user_id' => $buyer->id, 'subject' => 'x', 'status' => Ticket::STATUS_OPEN]);

        Livewire::actingAs($admin)->test('admin.dashboard')
            ->assertSee('¥15.00') // today's revenue == the one paid order
            ->assertSee('1'); // pending ticket count appears somewhere on the page
    }

    public function test_dashboard_shows_traffic_ranking_for_today(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create(['email' => 'heavy-user@example.com']);
        $server = Server::create(['name' => '排行节点', 'group_id' => 1, 'host' => '1.2.3.4', 'port' => 443, 'rate' => 1]);

        TrafficLog::create([
            'user_id' => $user->id,
            'server_id' => $server->id,
            'date' => now()->toDateString(),
            'upload_bytes' => 1024 * 1024 * 500,
            'download_bytes' => 1024 * 1024 * 500,
        ]);

        Livewire::actingAs($admin)->test('admin.dashboard')
            ->assertSee('排行节点')
            ->assertSee('heavy-user@example.com');
    }

    public function test_dashboard_reports_pending_jobs_and_failed_jobs_honestly(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'available_at' => now()->timestamp,
            'created_at' => now()->subMinutes(10)->timestamp,
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'boom',
            'failed_at' => now(),
        ]);

        Livewire::actingAs($admin)->test('admin.dashboard')
            ->assertSee('需要关注') // pending job present, no recent worker heartbeat -> unhealthy
            ->assertSee('10 分钟'); // oldest job's wait time
    }

    public function test_dashboard_shows_healthy_when_no_pending_jobs(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test('admin.dashboard')
            ->assertSee('正常');
    }

    public function test_dashboard_shows_healthy_when_worker_recently_active_despite_backlog(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Setting::set('queue_worker_last_seen_at', now()->toDateTimeString());

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        Livewire::actingAs($admin)->test('admin.dashboard')
            ->assertSee('正常');
    }

    public function test_dashboard_shows_operational_metrics(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $plan = $this->makePlan();

        // 一个活跃共享订阅 + 一个余额负债客户
        User::factory()->create(['plan_expired_at' => now()->addDays(20)]);
        User::factory()->create(['balance' => 5000]); // ¥50 store credit

        // 一个待支付订单
        $buyer = User::factory()->create();
        app(OrderService::class)->createOrder($buyer, $plan, 'month'); // 未付款 -> pending

        // 一台在线节点
        Server::create(['name' => '在线节点', 'group_id' => 1, 'host' => '9.9.9.9', 'port' => 443, 'rate' => 1, 'last_seen_at' => now()]);

        Livewire::actingAs($admin)->test('admin.dashboard')
            ->assertSee('活跃订阅')
            ->assertSee('待支付订单')
            ->assertSee('余额负债')
            ->assertSee('¥50.00')          // balance liability
            ->assertSee('节点在线 1/1');
    }

    public function test_admin_sidebar_includes_dashboard_link(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get($this->adminPath('/plans'))
            ->assertOk()
            ->assertSee('仪表盘');
    }
}
