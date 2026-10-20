<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Server;
use App\Models\SubscriptionInstance;
use App\Models\TrafficLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class TrafficReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_sqlite_transactions_use_immediate_mode_by_default(): void
    {
        $this->assertSame('IMMEDIATE', config('database.connections.sqlite.transaction_mode'));
    }

    public function test_server_traffic_report_creates_and_accumulates_a_daily_log(): void
    {
        $server = Server::create(['name' => 'x', 'group_id' => 1, 'host' => '1.2.3.4', 'port' => 443, 'rate' => 2.00]);
        $user = User::factory()->create();

        $this->postJson('/api/traffic/report', [
            'type' => 'server',
            'secret' => $server->heartbeat_secret,
            'uuid' => $user->uuid,
            'upload' => 1000,
            'download' => 2000,
        ])->assertStatus(200);

        $this->postJson('/api/traffic/report', [
            'type' => 'server',
            'secret' => $server->heartbeat_secret,
            'uuid' => $user->uuid,
            'upload' => 500,
            'download' => 500,
        ])->assertStatus(200);

        $log = TrafficLog::where('user_id', $user->id)->where('server_id', $server->id)->firstOrFail();
        $this->assertSame(1500, $log->upload_bytes);
        $this->assertSame(2500, $log->download_bytes);
        $this->assertSame('2.00', (string) $log->rate);
        $this->assertSame(1, TrafficLog::count()); // accumulated into one row, not two
    }

    public function test_instance_traffic_report_also_updates_instance_usage_totals(): void
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => 'x', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1073741824, 'prices' => ['month' => 1000],
            'resource_profile' => ['provider' => 'stub'], 'sell' => true,
        ]);
        $instance = SubscriptionInstance::create([
            'user_id' => $user->id, 'plan_id' => $plan->id,
            'transfer_enable' => 1073741824, 'status' => 'active', 'expired_at' => now()->addMonth(),
        ]);

        $this->postJson('/api/traffic/report', [
            'type' => 'instance',
            'secret' => $instance->token,
            'upload' => 300,
            'download' => 700,
        ])->assertStatus(200);

        $this->postJson('/api/traffic/report', [
            'type' => 'instance',
            'secret' => $instance->token,
            'upload' => 150,
            'download' => 50,
        ])->assertStatus(200);

        $log = TrafficLog::where('subscription_instance_id', $instance->id)->firstOrFail();
        $this->assertSame(450, $log->upload_bytes);
        $this->assertSame(750, $log->download_bytes);
        $this->assertSame('1.00', (string) $log->rate);

        $this->assertSame(450, $instance->fresh()->u);
        $this->assertSame(750, $instance->fresh()->d);
        $this->assertSame(1, TrafficLog::count());
    }

    public function test_zero_traffic_report_does_not_create_a_log(): void
    {
        $server = Server::create(['name' => 'x', 'group_id' => 1, 'host' => '1.2.3.4', 'port' => 443]);
        $user = User::factory()->create();

        $this->postJson('/api/traffic/report', [
            'type' => 'server',
            'secret' => $server->heartbeat_secret,
            'uuid' => $user->uuid,
            'upload' => 0,
            'download' => 0,
        ])->assertOk();

        $this->assertDatabaseCount('traffic_logs', 0);
    }

    public function test_invalid_secret_is_rejected(): void
    {
        $this->postJson('/api/traffic/report', [
            'type' => 'server',
            'secret' => 'nope',
            'uuid' => (string) Str::uuid(),
            'upload' => 100,
            'download' => 100,
        ])->assertStatus(404);
    }

    public function test_customer_can_view_own_traffic_logs(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        TrafficLog::create(['user_id' => $user->id, 'date' => now(), 'upload_bytes' => 1024, 'download_bytes' => 2048, 'rate' => 1]);
        TrafficLog::create(['user_id' => $other->id, 'date' => now(), 'upload_bytes' => 999, 'download_bytes' => 999, 'rate' => 1]);

        $component = Livewire::actingAs($user)->test('traffic');

        $logs = $component->viewData('logs');
        $this->assertSame(1, $logs->total());
        $this->assertSame($user->id, $logs->first()->user_id);
    }
}
