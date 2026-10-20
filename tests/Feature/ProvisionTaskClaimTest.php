<?php

namespace Tests\Feature;

use App\Jobs\ProcessProvisionTask;
use App\Models\Order;
use App\Models\Plan;
use App\Models\ProvisionTask;
use App\Models\SubscriptionInstance;
use App\Models\User;
use App\Services\Provisioners\ProvisionerInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProvisionTaskClaimTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:SubscriptionInstance,1:ProvisionTask} */
    private function makeTask(string $status): array
    {
        $user = User::factory()->create();
        $plan = Plan::create([
            'name' => 'ISP', 'type' => Plan::TYPE_DEDICATED_ISP,
            'transfer_enable' => 1, 'prices' => ['month' => 5000],
            'resource_profile' => ['provider' => 'stub'], 'sell' => true,
        ]);
        $instance = $user->subscriptionInstances()->create([
            'plan_id' => $plan->id, 'transfer_enable' => 1,
            'status' => SubscriptionInstance::STATUS_PENDING, 'expired_at' => now()->addMonth(),
        ]);
        $order = Order::create([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'type' => Order::TYPE_NEW,
            'period' => 'month', 'trade_no' => uniqid('t'), 'total_amount' => 5000, 'status' => Order::STATUS_PAID,
        ]);
        $task = ProvisionTask::create([
            'order_id' => $order->id, 'subscription_instance_id' => $instance->id,
            'status' => $status, 'payload' => [],
        ]);

        return [$instance, $task];
    }

    /** A provisioner that records how many times it was asked to provision. */
    private function countingProvisioner(): ProvisionerInterface
    {
        return new class implements ProvisionerInterface
        {
            public int $calls = 0;

            public function provision(ProvisionTask $task): array
            {
                $this->calls++;

                return ['provider' => 'stub', 'ip' => '9.9.9.9', 'protocol' => 'socks5'];
            }

            public function renewExisting(SubscriptionInstance $instance, string $period): void {}
        };
    }

    public function test_a_pending_task_is_provisioned_and_activated(): void
    {
        [$instance, $task] = $this->makeTask(ProvisionTask::STATUS_PENDING);
        $provisioner = $this->countingProvisioner();
        $this->app->instance(ProvisionerInterface::class, $provisioner);

        ProcessProvisionTask::dispatchSync($task->id);

        $this->assertSame(1, $provisioner->calls);
        $this->assertSame(ProvisionTask::STATUS_DONE, $task->fresh()->status);
        $this->assertSame(1, $task->fresh()->attempts);
        $this->assertSame(SubscriptionInstance::STATUS_ACTIVE, $instance->fresh()->status);
    }

    public function test_re_running_the_same_task_never_provisions_twice(): void
    {
        // The claim guard: once a task leaves PENDING, a retry / a raced second
        // worker must NOT call the provisioner again — that would place a
        // second real, money-spending onip order for one paid line.
        [, $task] = $this->makeTask(ProvisionTask::STATUS_PENDING);
        $provisioner = $this->countingProvisioner();
        $this->app->instance(ProvisionerInterface::class, $provisioner);

        ProcessProvisionTask::dispatchSync($task->id); // claims + provisions → DONE
        ProcessProvisionTask::dispatchSync($task->id); // task no longer PENDING → bails

        $this->assertSame(1, $provisioner->calls);
        $this->assertSame(ProvisionTask::STATUS_DONE, $task->fresh()->status);
    }

    public function test_a_task_already_processing_is_not_picked_up(): void
    {
        // Models the loser of a claim race (task already flipped to PROCESSING).
        [$instance, $task] = $this->makeTask(ProvisionTask::STATUS_PROCESSING);
        $provisioner = $this->countingProvisioner();
        $this->app->instance(ProvisionerInterface::class, $provisioner);

        ProcessProvisionTask::dispatchSync($task->id);

        $this->assertSame(0, $provisioner->calls);
        $this->assertSame(ProvisionTask::STATUS_PROCESSING, $task->fresh()->status);
        $this->assertSame(SubscriptionInstance::STATUS_PENDING, $instance->fresh()->status);
    }
}
