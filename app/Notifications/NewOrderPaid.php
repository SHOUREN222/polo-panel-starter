<?php

namespace App\Notifications;

use App\Models\Order;
use App\Notifications\Channels\TelegramChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

class NewOrderPaid extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order)
    {
    }

    /** Telegram where bound, e-mail otherwise — see NewTicketCreated. */
    public function via(object $notifiable): array
    {
        return $notifiable->isTelegramBound() ? [TelegramChannel::class] : ['mail'];
    }

    public function toMail(object $notifiable): \Illuminate\Notifications\Messages\MailMessage
    {
        $order = $this->order;
        $siteName = \App\Models\Setting::get('site_name', config('app.name'));

        return (new \Illuminate\Notifications\Messages\MailMessage)
            ->subject("【{$siteName}】新订单支付成功 ¥".number_format($order->total_amount / 100, 2))
            ->greeting('您好')
            ->line("客户 {$order->user->email} 完成了支付。")
            ->line('金额：¥'.number_format($order->total_amount / 100, 2))
            ->line("订单号：{$order->trade_no}");
    }

    public function toTelegram(object $notifiable): string
    {
        $order = $this->order;
        $amount = number_format($order->total_amount / 100, 2);

        return "<b>新订单支付成功</b>\n用户：{$order->user->email}\n金额：¥{$amount}\n订单号：{$order->trade_no}";
    }
}
