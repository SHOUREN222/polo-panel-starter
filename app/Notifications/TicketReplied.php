<?php

namespace App\Notifications;

use App\Mail\TemplatedMail;
use App\Models\EmailTemplate;
use App\Models\Setting;
use App\Models\TicketMessage;
use App\Notifications\Channels\TelegramChannel;
use App\Services\EmailTemplateService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

class TicketReplied extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public TicketMessage $message)
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
        $ticket = $this->message->ticket;
        $siteName = Setting::get('site_name', config('app.name'));

        // 站点通知 template slot (matches Xboard's generic "go check
        // something" email) — ticket replies are the closest real-world fit
        // among the notifications this app actually sends.
        $custom = app(EmailTemplateService::class)->render(EmailTemplate::KEY_SITE_NOTICE, [
            'name' => $siteName,
            'url' => \App\Support\SiteUrl::current(),
            'content' => "您的工单「{$ticket->subject}」有新的客服回复：{$this->message->content}",
        ], $notifiable);
        if ($custom) {
            return $custom;
        }

        return (new MailMessage)
            ->subject("工单有新回复 - {$ticket->subject} - {$siteName}")
            ->greeting('您好')
            ->line("您的工单「{$ticket->subject}」有新的客服回复：")
            ->line($this->message->content)
            ->action('查看工单', url('/tickets'))
            ->line('如果问题已解决，可以在工单页面关闭该工单。');
    }

    public function toTelegram(object $notifiable): string
    {
        $ticket = $this->message->ticket;

        return "<b>工单有新回复</b>\n「{$ticket->subject}」\n\n{$this->message->content}\n\n".url('/tickets');
    }
}
