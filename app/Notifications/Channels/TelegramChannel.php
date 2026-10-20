<?php

namespace App\Notifications\Channels;

use App\Models\Setting;
use App\Services\TelegramService;
use Illuminate\Notifications\Notification;

class TelegramChannel
{
    public function __construct(private TelegramService $telegram)
    {
    }

    public function send(object $notifiable, Notification $notification): void
    {
        if (! Setting::getBool('plugin.telegram_integration_enabled', true)) {
            return;
        }

        $chatId = $notifiable->routeNotificationFor('telegram');

        if (! $chatId || ! method_exists($notification, 'toTelegram')) {
            return;
        }

        $text = $notification->toTelegram($notifiable);

        if ($text) {
            $this->telegram->sendMessage($chatId, $text);
        }
    }
}
