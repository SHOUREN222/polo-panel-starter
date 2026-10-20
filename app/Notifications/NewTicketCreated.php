<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Notifications\Channels\TelegramChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

class NewTicketCreated extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Ticket $ticket)
    {
    }

    /**
     * Telegram where the admin has it bound, e-mail otherwise. Before this the
     * notification was Telegram-only and only sent to TG-bound admins, so a 分站
     * agent who hadn't linked an account never learned a customer had written
     * in — the customer just sat waiting.
     */
    public function via(object $notifiable): array
    {
        return $notifiable->isTelegramBound() ? [TelegramChannel::class] : ['mail'];
    }

    public function toMail(object $notifiable): \Illuminate\Notifications\Messages\MailMessage
    {
        $ticket = $this->ticket;
        $siteName = \App\Models\Setting::get('site_name', config('app.name'));

        return (new \Illuminate\Notifications\Messages\MailMessage)
            ->subject("【{$siteName}】新工单：{$ticket->subject}")
            ->greeting('您好')
            ->line("客户 {$ticket->user->email} 提交了新工单。")
            ->line("主题：{$ticket->subject}")
            ->line('请到后台「工单管理」处理。');
    }

    public function toTelegram(object $notifiable): string
    {
        $ticket = $this->ticket;

        return "<b>新工单提交</b>\n用户：{$ticket->user->email}\n主题：{$ticket->subject}";
    }
}
