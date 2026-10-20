<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Setting;
use App\Models\TrafficLog;
use App\Models\TrafficPack;
use App\Models\User;
use App\Services\OrderService;
use App\Services\SharedNodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SharedNodeServiceQuotaTest extends TestCase
{
    use RefreshDatabase;

    private function grantSharedPlan(array $planAttrs = []): array
    {
        $plan = Plan::create(array_merge([
            'name' => '共享套餐', 'type' => Plan::TYPE_SHARED, 'group_id' => 1,
            'transfer_enable' => 1024, 'prices' => ['month' => 1000], 'sell' => true,
        ], $planAttrs));
        $user = User::factory()->create();
        app(OrderService::class)->markPaid(app(OrderService::class)->createOrder($user, $plan, 'month'));

        return [$user->fresh(), $plan];
    }

    private function logTraffic(User $user, \DateTimeInterface $date, int $bytes): void
    {
        TrafficLog::create([
            'user_id' => $user->id, 'server_id' => null, 'date' => $date,
            'upload_bytes' => $bytes, 'download_bytes' => 0, 'rate' => 1,
        ]);
    }

    public function test_anniversary_mode_resets_on_the_users_own_grant_day_each_month(): void
    {
        Carbon::setTestNow('2026-07-20');

        [$user, $plan] = $this->grantSharedPlan(['traffic_reset_mode' => Plan::RESET_MODE_ANNIVERSARY]);
        $user->update(['shared_traffic_reset_at' => '2026-05-15']); // anniversary day = 15th

        $this->logTraffic($user, new \DateTime('2026-07-10'), 500); // before this month's 15th — excluded
        $this->logTraffic($user, new \DateTime('2026-07-16'), 300); // after — included

        $used = app(SharedNodeService::class)->sharedTrafficUsedBytes($user->fresh());
        $this->assertSame(300, $used);

        Carbon::setTestNow();
    }

    public function test_never_mode_counts_everything_since_the_original_grant(): void
    {
        Carbon::setTestNow('2026-07-20');

        [$user, $plan] = $this->grantSharedPlan(['traffic_reset_mode' => Plan::RESET_MODE_NEVER]);
        $user->update(['shared_traffic_reset_at' => '2026-01-01']);

        $this->logTraffic($user, new \DateTime('2026-02-01'), 500);
        $this->logTraffic($user, new \DateTime('2026-07-16'), 300);

        $used = app(SharedNodeService::class)->sharedTrafficUsedBytes($user->fresh());
        $this->assertSame(800, $used);

        Carbon::setTestNow();
    }

    public function test_one_time_traffic_pack_counts_forever(): void
    {
        Carbon::setTestNow('2026-07-20');
        [$user, $plan] = $this->grantSharedPlan(['traffic_reset_mode' => Plan::RESET_MODE_ANNIVERSARY, 'transfer_enable' => 1000]);
        $user->update(['shared_traffic_reset_at' => '2026-06-01']);

        TrafficPack::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'order_id' => 1, 'kind' => TrafficPack::KIND_ONE_TIME, 'bytes' => 500]);

        $quota = app(SharedNodeService::class)->effectiveSharedQuotaBytes($user->fresh());
        $this->assertSame(1500, $quota);

        // Roll forward past another anniversary — one-time pack still counts.
        Carbon::setTestNow('2026-09-05');
        $quota = app(SharedNodeService::class)->effectiveSharedQuotaBytes($user->fresh());
        $this->assertSame(1500, $quota);

        Carbon::setTestNow();
    }

    public function test_resettable_pack_only_counts_within_the_window_it_was_bought_in(): void
    {
        [$user, $plan] = $this->grantSharedPlan(['traffic_reset_mode' => Plan::RESET_MODE_ANNIVERSARY, 'transfer_enable' => 1000]);
        $user->update(['shared_traffic_reset_at' => '2026-06-01']); // anniversary day = 1st

        Carbon::setTestNow('2026-07-05');
        TrafficPack::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'order_id' => 1, 'kind' => TrafficPack::KIND_RESETTABLE, 'bytes' => 500]);

        $quota = app(SharedNodeService::class)->effectiveSharedQuotaBytes($user->fresh());
        $this->assertSame(1500, $quota, 'resettable pack should count within the same window it was bought');

        Carbon::setTestNow('2026-08-05'); // next month's window — the old resettable pack no longer applies
        $quota = app(SharedNodeService::class)->effectiveSharedQuotaBytes($user->fresh());
        $this->assertSame(1000, $quota);

        Carbon::setTestNow();
    }
}
