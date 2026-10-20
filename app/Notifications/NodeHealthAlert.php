<?php

namespace App\Notifications;

use App\Notifications\Channels\TelegramChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * Fired to platform admins the moment a node (V2bX shared node or static-ISP
 * relay) transitions offline — or recovers. This is the signal that was
 * missing when 主力 got bandwidth-suspended and the first anyone heard of it
 * was customer complaints hours later. Telegram only (a health signal must be
 * fast); no-op unless an admin has bound Telegram.
 */
class NodeHealthAlert extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $kind,   // 共享节点 / 中转节点
        public string $name,
        public string $target, // host:port
        public bool $recovered,
        public string $at,
    ) {}

    public function via(object $notifiable): array
    {
        return [TelegramChannel::class];
    }

    public function toTelegram(object $notifiable): string
    {
        if ($this->recovered) {
            return "<b>🟢 {$this->kind}已恢复</b>\n"
                ."节点：{$this->name}（{$this->target}）\n"
                ."时间：{$this->at}";
        }

        return "<b>🔴 {$this->kind}掉线</b>\n"
            ."节点：{$this->name}（{$this->target}）\n"
            ."时间：{$this->at}\n\n"
            .'客户可能受影响，请尽快检查（机房账单/流量、服务状态、网络）。';
    }
}
