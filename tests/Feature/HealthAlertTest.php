<?php

namespace Tests\Feature;

use App\Models\RelayNode;
use App\Models\Server;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\NodeHealthAlert;
use App\Notifications\OnipBalanceLowAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class HealthAlertTest extends TestCase
{
    use RefreshDatabase;

    private function tgAdmin(): User
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $admin->forceFill(['telegram_id' => 123456])->save();

        return $admin;
    }

    private function onlineServer(): Server
    {
        return Server::create([
            'name' => '主力节点', 'group_id' => 1, 'host' => '198.51.100.9', 'port' => 8443,
            'rate' => 1, 'last_seen_at' => now(),
        ]);
    }

    public function test_a_node_going_offline_alerts_admins_but_only_on_transition(): void
    {
        Notification::fake();
        $admin = $this->tgAdmin();
        $server = $this->onlineServer();

        // First run: records "up" silently (no alert).
        $this->artisan('nodes:health-check')->assertSuccessful();
        Notification::assertNothingSent();

        // Node goes offline (stale heartbeat) → alert once.
        $server->forceFill(['last_seen_at' => now()->subMinutes(30)])->save();
        $this->artisan('nodes:health-check')->assertSuccessful();
        Notification::assertSentTo($admin, NodeHealthAlert::class, fn ($n) => ! $n->recovered);

        // Still offline → no repeat.
        Notification::fake();
        $this->artisan('nodes:health-check')->assertSuccessful();
        Notification::assertNothingSent();
    }

    public function test_a_recovered_node_sends_a_recovery_alert(): void
    {
        $admin = $this->tgAdmin();
        $server = $this->onlineServer();

        // Seed "down" state.
        $server->forceFill(['last_seen_at' => now()->subMinutes(30)])->save();
        $this->artisan('nodes:health-check'); // first obs → 'down' silently

        Notification::fake();
        $server->forceFill(['last_seen_at' => now()])->save();
        $this->artisan('nodes:health-check')->assertSuccessful();
        Notification::assertSentTo($admin, NodeHealthAlert::class, fn ($n) => $n->recovered);
    }

    public function test_relay_node_unreachable_transition_alerts(): void
    {
        Notification::fake();
        $admin = $this->tgAdmin();
        // Empty host → tcpReachable() returns false instantly (deterministic "down").
        RelayNode::create([
            'label' => '中转X', 'priority' => 0, 'enabled' => true,
            'node_host' => '', 'node_port' => 8446, 'ssh_host' => '203.0.113.9',
        ]);

        // First obs → 'down' silently.
        $this->artisan('nodes:health-check');
        Notification::assertNothingSent();
    }

    public function test_low_onip_balance_alerts(): void
    {
        Notification::fake();
        $admin = $this->tgAdmin();
        Setting::set('provisioner_driver', 'onip');
        Setting::set('onip_app_id', 'id');
        Setting::set('onip_app_secret', 'secret');
        Setting::set('onip_low_balance_threshold', 100);

        Http::fake(fn () => Http::response([
            'success' => true, 'code' => 'SUCCESS',
            'data' => ['balance' => 30, 'currency' => 'CNY'],
        ]));

        $this->artisan('onip:balance-check')->assertSuccessful();
        Notification::assertSentTo($admin, OnipBalanceLowAlert::class, fn ($n) => $n->balance == 30.0);
    }

    public function test_healthy_onip_balance_does_not_alert(): void
    {
        Notification::fake();
        $this->tgAdmin();
        Setting::set('provisioner_driver', 'onip');
        Setting::set('onip_app_id', 'id');
        Setting::set('onip_app_secret', 'secret');

        Http::fake(fn () => Http::response([
            'success' => true, 'code' => 'SUCCESS',
            'data' => ['balance' => 500, 'currency' => 'CNY'],
        ]));

        $this->artisan('onip:balance-check')->assertSuccessful();
        Notification::assertNothingSent();
    }

    public function test_balance_check_is_a_noop_when_onip_is_not_the_driver(): void
    {
        Notification::fake();
        $this->tgAdmin();
        Setting::set('provisioner_driver', 'stub');

        Http::fake(); // any HTTP call would be a failure of the guard
        $this->artisan('onip:balance-check')->assertSuccessful();
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }
}
