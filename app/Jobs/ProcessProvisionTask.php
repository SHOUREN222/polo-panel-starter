<?php

namespace App\Jobs;

use App\Models\ProvisionTask;
use App\Models\SubscriptionInstance;
use App\Services\NodeRelayService;
use App\Services\Provisioners\ProvisionerInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessProvisionTask implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $provisionTaskId) {}

    public function handle(ProvisionerInterface $provisioner): void
    {
        $task = ProvisionTask::find($this->provisionTaskId);
        if (! $task) {
            return;
        }

        // Atomically claim the task: only the query that actually flips it out
        // of PENDING wins, so two workers can never both call the provisioner
        // (which places a real, money-spending onip order) for the same task —
        // the loser's update matches 0 rows and it bails. Belt-and-suspenders
        // over the queue's own per-job reservation.
        $claimed = ProvisionTask::whereKey($task->getKey())
            ->where('status', ProvisionTask::STATUS_PENDING)
            ->update([
                'status' => ProvisionTask::STATUS_PROCESSING,
                'attempts' => DB::raw('attempts + 1'),
            ]);

        if ($claimed === 0) {
            return;
        }

        $task->refresh();

        try {
            $result = $provisioner->provision($task);

            DB::transaction(function () use ($task, $result) {
                $task->update([
                    'status' => ProvisionTask::STATUS_DONE,
                    'result' => $result,
                    'error' => null,
                ]);

                $task->subscriptionInstance->update([
                    'status' => SubscriptionInstance::STATUS_ACTIVE,
                    'meta' => $result,
                ]);
            });

            // onip lines are delivered via the 主力 relay — push the updated
            // node config so this instance's residential IP is routable.
            // Guarded: a node/SSH hiccup must not fail the (already-committed)
            // provision; it can be re-synced later.
            if (($result['provider'] ?? null) === 'onip') {
                try {
                    app(NodeRelayService::class)->sync();
                } catch (\Throwable $e) {
                    Log::warning('relay sync after provision failed', ['task_id' => $task->id, 'error' => $e->getMessage()]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('ProvisionTask failed', ['task_id' => $task->id, 'error' => $e->getMessage()]);

            $task->update([
                'status' => ProvisionTask::STATUS_FAILED,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
