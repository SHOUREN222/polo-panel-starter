<?php

namespace App\Console\Commands;

use App\Services\NodeRelayService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('relay:sync {--force : Re-push every node even if its config is unchanged}')]
#[Description('Reconcile the static-ISP relay config to every relay node; auto-repairs a node that was unreachable (e.g. 主力 down) the moment its SSH comes back. No-op (no reload) for nodes already in sync.')]
class RelaySync extends Command
{
    public function handle(NodeRelayService $relay): int
    {
        $result = $relay->sync(force: (bool) $this->option('force'));

        // A node that just went from behind -> synced is the interesting
        // event (e.g. 主力 recovered). Log only when something was actually
        // pushed, so the every-few-minutes idle runs stay silent.
        if (! empty($result['nodes'])) {
            Log::info('relay:sync pushed config to node(s)', [
                'pushed' => $result['nodes'],
                'failed' => $result['failed'] ?? [],
                'instances' => $result['instances'] ?? null,
            ]);
        }

        $this->info(json_encode($result, JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
