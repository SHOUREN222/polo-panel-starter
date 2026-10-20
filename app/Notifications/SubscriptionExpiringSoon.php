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

class SubscriptionExpiringSoon extends Notification implements ShouldQueue
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
        $expiredAt = $this->instance->expired_at->format('Y-m-d H:i');

        $custom = app(EmailTemplateService::class)->render(EmailTemplate::KEY_EXPIRE_REMINDER, [
            'name' => $siteName,
            'url' => \App\Support\SiteUrl::current(),
            'plan_name' => $planName,
            'expired_at' => $expiredAt,
        ], $notifiable);
        if ($custom) {
            return $custom;
        }

        return (new MailMessage)
            ->subject("订阅即将到期 - {$siteName}")
            ->greeting('您好')
            ->line("您的套餐「{$planName}」将于 {$expiredAt} 到期。")
            ->line('到期后该线路会停止服务，如需继续使用请提前续费。')
            ->action('立即续费', url('/renew'))
            ->line('如果您已经续费，请忽略此邮件。');
    }

    public function toTelegram(object $notifiable): string
    {
        $planName = $this->instance->plan->name;
        $expiredAt = $this->instance->expired_at->format('Y-m-d H:i');

        return "<b>订阅即将到期</b>\n套餐「{$planName}」将于 {$expiredAt} 到期，到期后停止服务。\n\n".url('/renew');
    }
}
