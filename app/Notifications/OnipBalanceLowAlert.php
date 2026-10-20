<?php

namespace App\Notifications;

use App\Notifications\Channels\TelegramChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * Fired to platform admins when the onip account balance drops below the
 * configured threshold. Without it, a drained onip balance surfaces only when
 * a customer's static-ISP purchase or renewal starts failing — the renewal
 * now rolls back cleanly (no charge for undelivered time), but the customer
 * still can't buy and nobody knows to top onip up. This gives you the heads-up.
 */
class OnipBalanceLowAlert extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public float $balance,
        public string $currency,
        public float $threshold,
    ) {}

    public function via(object $notifiable): array
    {
        return [TelegramChannel::class];
    }

    public function toTelegram(object $notifiable): string
    {
        return "<b>⚠️ onip 余额偏低</b>\n"
            ."当前余额：{$this->balance} {$this->currency}\n"
            ."阈值：{$this->threshold} {$this->currency}\n\n"
            .'余额耗尽后静态ISP的开通/续费会失败。请尽快给 onip 账户充值。';
    }
}
