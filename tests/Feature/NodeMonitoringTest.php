<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NodeMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_is_assigned_a_heartbeat_secret_on_creation(): void
    {
        $server = Server::create(['name' => 'x', 'group_id' => 1, 'host' => '1.2.3.4', 'port' => 443]);

        $this->assertNotEmpty($server->heartbeat_secret);
    }

    public function test_server_is_offline_until_a_heartbeat_is_received(): void
    {
        $server = Server::create(['name' => 'x', 'group_id' => 1, 'host' => '1.2.3.4', 'port' => 443]);

        $this->assertFalse($server->isOnline());

        $this->postJson('/api/servers/heartbeat', ['secret' => $server->heartbeat_secret])
            ->assertStatus(200);

        $this->assertTrue($server->fresh()->isOnline());
    }

    public function test_heartbeat_with_invalid_secret_is_rejected(): void
    {
        $this->postJson('/api/servers/heartbeat', ['secret' => 'nope'])
            ->assertStatus(404);
    }
}
