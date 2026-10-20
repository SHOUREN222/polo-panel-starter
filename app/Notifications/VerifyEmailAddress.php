<?php

namespace App\Notifications;

use App\Mail\TemplatedMail;
use App\Models\EmailTemplate;
use App\Models\Setting;
use App\Services\EmailTemplateService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class VerifyEmailAddress extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage|TemplatedMail
    {
        $siteName = Setting::get('site_name', config('app.name'));

        $url = URL::temporarySignedRoute('verify-email', now()->addMinutes(60), [
            'id' => $notifiable->getKey(),
            'hash' => sha1($notifiable->email),
        ]);

        $custom = app(EmailTemplateService::class)->render(EmailTemplate::KEY_VERIFY_EMAIL, [
            'name' => $siteName,
            'url' => \App\Support\SiteUrl::current(),
            'link' => $url,
        ], $notifiable);
        if ($custom) {
            return $custom;
        }

        return (new MailMessage)
            ->subject("验证您的邮箱 - {$siteName}")
            ->greeting('您好')
            ->line('请点击下方按钮验证您的邮箱地址。')
            ->action('验证邮箱', $url)
            ->line('此链接 60 分钟内有效。')
            ->line('如果这不是您本人的操作，可以忽略这封邮件。');
    }
}
