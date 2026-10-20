<?php

namespace App\Notifications;

use App\Notifications\Channels\TelegramChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to an admin the moment their account logs in from a new IP, so a
 * compromised admin login can't happen silently. Delivered over Telegram only
 * (email would be too slow / easy to miss for a security signal); does nothing
 * unless the admin has bound Telegram.
 */
class AdminLoginAlert extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $ip,
        public string $at,
        public bool $firstLogin,
    ) {
    }

    public function via(object $notifiable): array
    {
        return [TelegramChannel::class];
    }

    public function toTelegram(object $notifiable): string
    {
        $head = $this->firstLogin ? '后台账号首次登录' : '后台账号在新 IP 登录';

        return "<b>⚠️ {$head}</b>\n"
            ."账号：{$notifiable->email}\n"
            ."IP：{$this->ip}\n"
            ."时间：{$this->at}\n\n"
            .'如果不是你本人操作，请立即修改密码。';
    }
}
