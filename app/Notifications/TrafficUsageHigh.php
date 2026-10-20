<?php

namespace App\Notifications;

use App\Mail\TemplatedMail;
use App\Models\EmailTemplate;
use App\Models\Setting;
use App\Models\SubscriptionInstance;
use App\Notifications\Channels\TelegramChannel;
use App\Services\EmailTemplateService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

class TrafficUsageHigh extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public SubscriptionInstance $instance)
    {
    }

    public function via(object $notifiable): array
    {
        $channels = ['mail'];

        if ($notifiable->isTelegramBound()) {
            $channels[] = TelegramChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage|TemplatedMail
    {
        $siteName = Setting::get('site_name', config('app.name'));
        $planName = $this->instance->plan->name;
        $percent = (int) round($this->instance->usageRatio() * 100);

        $custom = app(EmailTemplateService::class)->render(EmailTemplate::KEY_TRAFFIC_REMINDER, [
            'name' => $siteName,
            'url' => \App\Support\SiteUrl::current(),
            'plan_name' => $planName,
            'percent' => (string) $percent,
        ], $notifiable);
        if ($custom) {
            return $custom;
        }

        return (new MailMessage)
            ->subject("流量即将用完 - {$siteName}")
            ->greeting('您好')
            ->line("您的套餐「{$planName}」流量已使用 {$percent}%。")
            ->line('用完后该线路会限速或停止服务，如需更多流量请续费或升级套餐。')
            ->action('查看流量明细', url('/traffic'))
            ->line('如果您已经处理，请忽略此邮件。');
    }

    public function toTelegram(object $notifiable): string
    {
        $planName = $this->instance->plan->name;
        $percent = (int) round($this->instance->usageRatio() * 100);

        return "<b>流量即将用完</b>\n套餐「{$planName}」流量已使用 {$percent}%。\n\n".url('/traffic');
    }
}
