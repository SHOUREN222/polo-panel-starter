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

class MagicLoginLink extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage|TemplatedMail
    {
        $siteName = Setting::get('site_name', config('app.name'));

        $url = URL::temporarySignedRoute('login-via-email', now()->addMinutes(5), [
            'id' => $notifiable->getKey(),
        ]);

        $custom = app(EmailTemplateService::class)->render(EmailTemplate::KEY_MAGIC_LOGIN, [
            'name' => $siteName,
            'url' => \App\Support\SiteUrl::current(),
            'link' => $url,
        ], $notifiable);
        if ($custom) {
            return $custom;
        }

        return (new MailMessage)
            ->subject("邮件登录 - {$siteName}")
            ->greeting('您好')
            ->line("点击下方按钮登录到 {$siteName}，链接有效期 5 分钟。如非本人操作，请忽略此邮件。")
            ->action('确认登录', $url);
    }
}
