<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\SubscriptionInstance;
use App\Notifications\SubscriptionExpiringSoon;
use App\Notifications\TrafficUsageHigh;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:send-usage-reminders')]
#[Description('Email customers whose subscriptions are about to expire or nearly out of traffic')]
class SendUsageReminders extends Command
{
    private const EXPIRY_WINDOW_DAYS = 3;

    private const TRAFFIC_THRESHOLD = 0.8;

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        if (! Setting::getBool('mail_reminders_enabled', true)) {
            $this->info('邮件提醒 is disabled in 系统设置, skipping.');

            return;
        }

        $today = now()->toDateString();
        $sentExpiry = 0;
        $sentTraffic = 0;

        // Subscriptions live in each site's own database and the scheduler runs
        // in central context, so without sweeping the 分站 their customers
        // would simply never be reminded that a plan is about to lapse.
        \App\Support\EachSite::run(function () use ($today, &$sentExpiry, &$sentTraffic) {
        $expiring = SubscriptionInstance::query()
            ->with(['user', 'plan'])
            ->where('status', '!=', SubscriptionInstance::STATUS_DISABLED)
            ->whereNotNull('expired_at')
            ->whereBetween('expired_at', [now(), now()->addDays(self::EXPIRY_WINDOW_DAYS)])
            ->where(function ($q) use ($today) {
                $q->whereNull('expire_reminder_sent_at')
                    ->orWhereDate('expire_reminder_sent_at', '!=', $today);
            })
            ->get()
            ->filter(fn (SubscriptionInstance $i) => $i->user && $i->user->notify_expire);

        foreach ($expiring as $instance) {
            $instance->user->notify(new SubscriptionExpiringSoon($instance));
            $instance->update(['expire_reminder_sent_at' => $today]);
        }

        $highUsage = SubscriptionInstance::query()
            ->with(['user', 'plan'])
            ->where('status', SubscriptionInstance::STATUS_ACTIVE)
            ->where('transfer_enable', '>', 0)
            ->where(function ($q) use ($today) {
                $q->whereNull('traffic_reminder_sent_at')
                    ->orWhereDate('traffic_reminder_sent_at', '!=', $today);
            })
            ->get()
            ->filter(fn (SubscriptionInstance $i) => ! $i->isExpired()
                && $i->usageRatio() >= self::TRAFFIC_THRESHOLD
                && $i->user
                && $i->user->notify_traffic);

        foreach ($highUsage as $instance) {
            $instance->user->notify(new TrafficUsageHigh($instance));
            $instance->update(['traffic_reminder_sent_at' => $today]);
        }

        $sentExpiry += $expiring->count();
        $sentTraffic += $highUsage->count();
        });

        $this->info(sprintf('Sent %d expiry reminders and %d traffic reminders.', $sentExpiry, $sentTraffic));
    }
}
