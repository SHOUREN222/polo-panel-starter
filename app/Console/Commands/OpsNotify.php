<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Console\Command;

/**
 * Send an operational alert to every Telegram-bound admin. Used by ops scripts
 * (e.g. the nightly DB backup) so a silent failure surfaces immediately. This
 * is an admin-facing alert, so it sends whenever a bot token + bound admin
 * exist — it is not gated by the customer-facing telegram integration toggle.
 */
class OpsNotify extends Command
{
    protected $signature = 'ops:notify {message : Text to send to Telegram-bound admins}';

    protected $description = 'Send an ops/alert message to all Telegram-bound admins';

    public function handle(TelegramService $telegram): int
    {
        $admins = User::telegramBoundAdmins();

        if ($admins->isEmpty()) {
            $this->warn('No Telegram-bound admins — nothing sent.');

            return self::SUCCESS;
        }

        $text = $this->argument('message');
        $sent = 0;

        foreach ($admins as $admin) {
            if ($telegram->sendMessage($admin->telegram_id, $text)) {
                $sent++;
            }
        }

        $this->info("Notified {$sent}/{$admins->count()} admin(s).");

        return self::SUCCESS;
    }
}
