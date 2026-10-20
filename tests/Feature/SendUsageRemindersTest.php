<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Setting;
use App\Models\SubscriptionInstance;
use App\Models\User;
use App\Notifications\SubscriptionExpiringSoon;
use App\Notifications\TrafficUsageHigh;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendUsageRemindersTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_a_noop_when_mail_reminders_disabled(): void
    {
        Notification::fake();
        Setting::set('mail_reminders_enabled', false);

        $user = User::factory()->create(['notify_expire' => true]);
        $plan = $this->makePlan();
        SubscriptionInstance::create([
            'user_id' => $user->id, 'plan_id' => $plan->id,
            'transfer_enable' => $plan->transfer_enable, 'status' => 'active',
            'expired_at' => now()->addDays(2),
        ]);

        $this->artisan('app:send-usage-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    private function makePlan(string $type = Plan::TYPE_DEDICATED_ISP, int $transferEnable = 1073741824): Plan
    {
        return Plan::create([
            'name' => 'x', 'type' => $type,
            'transfer_enable' => $transferEnable, 'prices' => ['month' => 1000],
            'resource_profile' => ['provider' => 'stub'], 'sell' => true,
        ]);
    }

    public function test_sends_expiry_reminder_for_instance_expiring_within_window(): void
    {
        Notification::fake();

        $user = User::factory()->create(['notify_expire' => true]);
        $plan = $this->makePlan();
        $instance = SubscriptionInstance::create([
            'user_id' => $user->id, 'plan_id' => $plan->id,
            'transfer_enable' => $plan->transfer_enable, 'status' => 'active',
            'expired_at' => now()->addDays(2),
        ]);

        $this->artisan('app:send-usage-reminders');

        Notification::assertSentTo($user, SubscriptionExpiringSoon::class);
        $this->assertNotNull($instance->fresh()->expire_reminder_sent_at);
    }

    public function test_does_not_send_expiry_reminder_twice_in_the_same_day(): void
    {
        Notification::fake();

        $user = User::factory()->create(['notify_expire' => true]);
        $plan = $this->makePlan();
        SubscriptionInstance::create([
            'user_id' => $user->id, 'plan_id' => $plan->id,
            'transfer_enable' => $plan->transfer_enable, 'status' => 'active',
            'expired_at' => now()->addDays(2),
        ]);

        $this->artisan('app:send-usage-reminders');
        $this->artisan('app:send-usage-reminders');

        Notification::assertSentToTimes($user, SubscriptionExpiringSoon::class, 1);
    }

    public function test_skips_expiry_reminder_when_user_opted_out(): void
    {
        Notification::fake();

        $user = User::factory()->create(['notify_expire' => false]);
        $plan = $this->makePlan();
        SubscriptionInstance::create([
            'user_id' => $user->id, 'plan_id' => $plan->id,
            'transfer_enable' => $plan->transfer_enable, 'status' => 'active',
            'expired_at' => now()->addDays(2),
        ]);

        $this->artisan('app:send-usage-reminders');

        Notification::assertNothingSent();
    }

    public function test_skips_expiry_reminder_when_outside_window(): void
    {
        Notification::fake();

        $user = User::factory()->create(['notify_expire' => true]);
        $plan = $this->makePlan();
        SubscriptionInstance::create([
            'user_id' => $user->id, 'plan_id' => $plan->id,
            'transfer_enable' => $plan->transfer_enable, 'status' => 'active',
            'expired_at' => now()->addDays(10),
        ]);

        $this->artisan('app:send-usage-reminders');

        Notification::assertNothingSent();
    }

    public function test_sends_traffic_reminder_when_usage_crosses_threshold(): void
    {
        Notification::fake();

        $user = User::factory()->create(['notify_traffic' => true]);
        $plan = $this->makePlan(transferEnable: 1000);
        $instance = SubscriptionInstance::create([
            'user_id' => $user->id, 'plan_id' => $plan->id,
            'transfer_enable' => 1000, 'u' => 850, 'd' => 0,
            'status' => 'active', 'expired_at' => now()->addMonth(),
        ]);

        $this->artisan('app:send-usage-reminders');

        Notification::assertSentTo($user, TrafficUsageHigh::class);
        $this->assertNotNull($instance->fresh()->traffic_reminder_sent_at);
    }

    public function test_skips_traffic_reminder_below_threshold(): void
    {
        Notification::fake();

        $user = User::factory()->create(['notify_traffic' => true]);
        $plan = $this->makePlan(transferEnable: 1000);
        SubscriptionInstance::create([
            'user_id' => $user->id, 'plan_id' => $plan->id,
            'transfer_enable' => 1000, 'u' => 100, 'd' => 0,
            'status' => 'active', 'expired_at' => now()->addMonth(),
        ]);

        $this->artisan('app:send-usage-reminders');

        Notification::assertNothingSent();
    }
}
