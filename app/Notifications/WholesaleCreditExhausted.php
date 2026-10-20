<?php

namespace App\Notifications;

use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to a 分站's own admins the moment an order can't be delivered because
 * their 进货余额 is spent. They're the only ones who can fix it (by topping up
 * with 站长), and nothing else would tell them — the customer just sees a
 * failed activation.
 */
class WholesaleCreditExhausted extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(private int $balance, private int $needed)
    {
    }

    public function via(object $notifiable): array
    {
        return $notifiable->isTelegramBound() ? ['mail', 'telegram'] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $siteName = Setting::get('site_name', config('app.name'));

        return (new MailMessage)
            ->subject("【{$siteName}】进货余额不足，客户订单无法开通")
            ->greeting('您好')
            ->line('刚刚有一笔已支付的客户订单**无法开通**，因为本站的进货余额不够。')
            ->line('当前余额：¥'.number_format($this->balance / 100, 2))
            ->line('本单需要：¥'.number_format($this->needed / 100, 2))
            ->line('请尽快联系站长充值。充值后，客户可以重新尝试开通，订单不会丢失。')
            ->line('（为避免打扰，同类提醒每小时最多发送一次。）');
    }

    public function toTelegram(object $notifiable): string
    {
        return "⚠️ <b>进货余额不足</b>\n\n"
            ."刚有一笔已支付的客户订单无法开通。\n"
            .'当前余额：¥'.number_format($this->balance / 100, 2)."\n"
            .'本单需要：¥'.number_format($this->needed / 100, 2)."\n\n"
            .'请尽快联系站长充值，充值后客户可重新开通。';
    }
}
