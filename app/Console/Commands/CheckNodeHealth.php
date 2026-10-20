<?php

namespace App\Console\Commands;

use App\Models\RelayNode;
use App\Models\Server;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\NodeHealthAlert;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

#[Signature('nodes:health-check')]
#[Description('Alert platform admins (Telegram) when a shared node or relay node goes offline or recovers. Only fires on state transitions.')]
class CheckNodeHealth extends Command
{
    public function handle(): int
    {
        $admins = User::telegramBoundAdmins();

        // Shared V2bX nodes: heartbeat-based liveness.
        foreach (Server::all() as $server) {
            $this->evaluate(
                key: 'node_health:server:'.$server->id,
                up: $server->isOnline(),
                kind: '共享节点',
                name: $server->name,
                target: $server->host.':'.$server->port,
                admins: $admins,
            );
        }

        // Relay nodes: TCP-dial the customer-facing port (cheap liveness).
        foreach (RelayNode::where('enabled', true)->get() as $node) {
            $this->evaluate(
                key: 'node_health:relay:'.$node->id,
                up: $this->tcpReachable($node->node_host, (int) $node->node_port),
                kind: '中转节点',
                name: $node->label,
                target: $node->node_host.':'.$node->node_port,
                admins: $admins,
            );
        }

        return self::SUCCESS;
    }

    private function evaluate(string $key, bool $up, string $kind, string $name, string $target, $admins): void
    {
        $prev = Setting::get($key); // 'up' | 'down' | null (never seen)
        $now = $up ? 'up' : 'down';

        if ($prev === $now) {
            return; // no change
        }

        Setting::set($key, $now);

        // First observation: record silently, don't alert (avoids a burst of
        // "recovered/offline" messages on first deploy for already-known state).
        if ($prev === null) {
            return;
        }

        $this->info("{$kind} {$name}: {$prev} -> {$now}");

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new NodeHealthAlert(
                kind: $kind,
                name: $name,
                target: $target,
                recovered: $up,
                at: now()->format('Y-m-d H:i:s'),
            ));
        }
    }

    private function tcpReachable(string $host, int $port, float $timeout = 5.0): bool
    {
        if ($host === '' || $port <= 0) {
            return false;
        }

        $conn = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($conn === false) {
            return false;
        }
        fclose($conn);

        return true;
    }
}
