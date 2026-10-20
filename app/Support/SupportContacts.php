<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * Builds the list of customer-support channels shown by the floating
 * "联系客服" widget from admin-configured Setting values. Each configured
 * channel renders; blanks are skipped. Telegram entries carry both a web
 * (t.me) URL and a tg:// app-scheme link — the widget prefers the app link
 * so it still opens where t.me is blocked (e.g. mainland China).
 */
class SupportContacts
{
    public static function enabled(): bool
    {
        return Setting::getBool('support_widget_enabled', false);
    }

    public static function note(): string
    {
        return trim((string) Setting::get('support_note', ''));
    }

    /**
     * @return array<int, array{type:string,icon:string,label:string,value:string,href?:string,appHref?:string,copy?:string}>
     */
    public static function all(): array
    {
        if (! self::enabled()) {
            return [];
        }

        $items = [];

        if (($tg = trim((string) Setting::get('support_telegram', ''))) !== '') {
            [$web, $app] = self::telegramLinks($tg);
            $items[] = ['type' => 'link', 'icon' => 'telegram', 'label' => 'Telegram 客服', 'value' => self::telegramDisplay($tg), 'href' => $app ?: $web, 'appHref' => $app, 'copy' => $web];
        }

        if (($group = trim((string) Setting::get('support_telegram_group', ''))) !== '') {
            [$web, $app] = self::telegramLinks($group);
            $items[] = ['type' => 'link', 'icon' => 'telegram', 'label' => 'Telegram 交流群', 'value' => self::telegramDisplay($group), 'href' => $app ?: $web, 'appHref' => $app, 'copy' => $web];
        }

        if (($email = trim((string) Setting::get('support_email', ''))) !== '') {
            $items[] = ['type' => 'link', 'icon' => 'mail', 'label' => '邮箱客服', 'value' => $email, 'href' => 'mailto:'.$email, 'copy' => $email];
        }

        $items[] = self::scannable('微信', 'support_wechat', 'support_wechat_qr');
        $items[] = self::scannable('QQ', 'support_qq', 'support_qq_qr');

        return array_values(array_filter($items));
    }

    /**
     * A WeChat/QQ-style channel: an ID that can't be turned into a link, so
     * the customer either copies the ID or scans an uploaded QR. Shows when
     * either the ID or the QR is configured; returns null when both are blank.
     */
    private static function scannable(string $label, string $idKey, string $qrKey): ?array
    {
        $id = trim((string) Setting::get($idKey, ''));
        $qrPath = trim((string) Setting::get($qrKey, ''));

        if ($id === '' && $qrPath === '') {
            return null;
        }

        $item = [
            'type' => 'copy',
            'icon' => 'copy',
            'label' => $label,
            'value' => $id !== '' ? $id : '点击扫码添加',
        ];

        if ($id !== '') {
            $item['copy'] = $id;
        }
        if ($qrPath !== '') {
            $item['qr'] = Storage::disk('public')->url($qrPath);
        }

        return $item;
    }

    /**
     * Normalize a Telegram handle or link into [webUrl, appUrl]. Accepts a
     * bare/@ username or any t.me URL (public @name or private +invite link).
     * appUrl is a tg:// scheme that opens the Telegram app directly.
     */
    private static function telegramLinks(string $raw): array
    {
        $raw = trim($raw);

        // Private invite link: t.me/+CODE or t.me/joinchat/CODE
        if (preg_match('~t\.me/(?:joinchat/|\+)([A-Za-z0-9_-]+)~', $raw, $m)) {
            return [$raw, 'tg://join?invite='.$m[1]];
        }

        // Public t.me link → pull the username.
        if (preg_match('~t\.me/([A-Za-z0-9_]+)~', $raw, $m)) {
            return ['https://t.me/'.$m[1], 'tg://resolve?domain='.$m[1]];
        }

        // Bare handle (optionally @-prefixed).
        $handle = ltrim($raw, '@');
        if ($handle !== '' && preg_match('~^[A-Za-z0-9_]+$~', $handle)) {
            return ['https://t.me/'.$handle, 'tg://resolve?domain='.$handle];
        }

        // Unparseable — treat as a raw URL with no app link.
        return [$raw, null];
    }

    private static function telegramDisplay(string $raw): string
    {
        $raw = trim($raw);

        if (preg_match('~t\.me/(?:joinchat/|\+)~', $raw)) {
            return '私密邀请链接';
        }

        if (preg_match('~t\.me/([A-Za-z0-9_]+)~', $raw, $m)) {
            return '@'.$m[1];
        }

        $handle = ltrim($raw, '@');

        return preg_match('~^[A-Za-z0-9_]+$~', $handle) ? '@'.$handle : $raw;
    }
}
