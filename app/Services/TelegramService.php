<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    public function sendMessage(int|string $chatId, string $text): bool
    {
        $token = Setting::get('telegram_bot_token');

        if (! $token) {
            return false;
        }

        $response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ]);

        if (! $response->successful()) {
            Log::warning('Telegram sendMessage failed', ['chat_id' => $chatId, 'response' => $response->body()]);
        }

        return $response->successful();
    }

    /**
     * Register the bot's command menu (the list shown in Telegram's "/" popup)
     * so it matches what this panel actually handles — otherwise stale commands
     * from a previous integration linger and look broken when tapped.
     *
     * @param  array<int, array{command: string, description: string}>  $commands
     */
    public function setMyCommands(array $commands): bool
    {
        $token = Setting::get('telegram_bot_token');

        if (! $token) {
            return false;
        }

        $response = Http::asJson()->post("https://api.telegram.org/bot{$token}/setMyCommands", [
            'commands' => $commands,
        ]);

        if (! $response->successful()) {
            Log::warning('Telegram setMyCommands failed', ['response' => $response->body()]);
        }

        return $response->successful();
    }

    /**
     * Long-poll for new updates since the given offset. Returns the raw
     * "result" array from the Bot API (each item has an "update_id").
     */
    public function getUpdates(int $offset, int $timeout = 25): array
    {
        $token = Setting::get('telegram_bot_token');

        if (! $token) {
            return [];
        }

        $response = Http::timeout($timeout + 10)->get("https://api.telegram.org/bot{$token}/getUpdates", [
            'offset' => $offset,
            'timeout' => $timeout,
        ]);

        if (! $response->successful()) {
            Log::warning('Telegram getUpdates failed', ['response' => $response->body()]);

            return [];
        }

        return $response->json('result', []);
    }

    public function botUsername(): ?string
    {
        return Setting::get('telegram_bot_username') ?: null;
    }

    public function deepLinkFor(string $bindCode): ?string
    {
        $username = $this->botUsername();

        return $username ? "https://t.me/{$username}?start={$bindCode}" : null;
    }

    /**
     * The tg:// scheme link that opens the Telegram app directly, bypassing the
     * t.me web page entirely — important where t.me is blocked/unreachable (e.g.
     * mainland China) but the Telegram app itself works over its own proxy.
     */
    public function appLinkFor(string $bindCode): ?string
    {
        $username = $this->botUsername();

        return $username ? "tg://resolve?domain={$username}&start={$bindCode}" : null;
    }

    /**
     * Convert a t.me group/channel web URL into the tg:// scheme that opens the
     * Telegram app directly (bypasses the t.me web page, which is blocked in
     * mainland China). Handles private-invite links (t.me/+CODE or
     * t.me/joinchat/CODE → tg://join?invite=CODE) and public @usernames
     * (t.me/name → tg://resolve?domain=name). Returns null if it can't parse a
     * t.me link, in which case callers should fall back to the raw URL.
     */
    public function appLinkForGroup(?string $webUrl): ?string
    {
        if (! $webUrl) {
            return null;
        }

        if (preg_match('~t\.me/(?:joinchat/|\+)([A-Za-z0-9_-]+)~', $webUrl, $m)) {
            return 'tg://join?invite='.$m[1];
        }

        if (preg_match('~t\.me/([A-Za-z0-9_]+)~', $webUrl, $m)) {
            return 'tg://resolve?domain='.$m[1];
        }

        return null;
    }
}
