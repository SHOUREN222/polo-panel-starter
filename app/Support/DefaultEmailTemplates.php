<?php

namespace App\Support;

use App\Models\EmailTemplate;

/**
 * The built-in HTML email templates shown in the editor and seeded as the
 * active templates. Kept here (not inside the Livewire settings component)
 * so both the editor's "default/reset" view and the seeding command share
 * one source of truth — the HTML shouldn't live in two places and drift.
 *
 * All styles are inline and table-based on purpose: most mail clients strip
 * <style> blocks and ignore flexbox/grid. {{name}} and {{url}} are part of
 * every template's token set (see the Notification classes), so the shell
 * can safely use them in the header/footer.
 */
class DefaultEmailTemplates
{
    /**
     * @return array{subject:string,content:string}
     */
    public static function for(string $key): array
    {
        return match ($key) {
            EmailTemplate::KEY_VERIFY_EMAIL => [
                'subject' => '验证您的邮箱 - {{name}}',
                'content' => self::shell(
                    '验证您的邮箱',
                    '<p style="margin:0 0 24px;font-size:14px;line-height:1.65;color:#52525b;">感谢注册，请点击下面的按钮完成邮箱验证。链接 60 分钟内有效。</p>'
                    .self::button('验证邮箱', '{{link}}')
                    .'<p style="margin:26px 0 0;font-size:12px;line-height:1.6;color:#a1a1aa;">如果按钮无法点击，请复制以下链接到浏览器打开：</p>'
                    .'<p style="margin:6px 0 0;font-size:12px;line-height:1.5;color:#71717a;word-break:break-all;">{{link}}</p>'
                    .'<p style="margin:20px 0 0;font-size:12px;color:#a1a1aa;">如果这不是您本人的操作，请忽略此邮件。</p>'
                ),
            ],
            EmailTemplate::KEY_MAGIC_LOGIN => [
                'subject' => '邮件登录 - {{name}}',
                'content' => self::shell(
                    '登录到 {{name}}',
                    '<p style="margin:0 0 24px;font-size:14px;line-height:1.65;color:#52525b;">点击下面的按钮直接登录，无需输入密码。链接 5 分钟内有效。</p>'
                    .self::button('登录账号', '{{link}}')
                    .'<p style="margin:26px 0 0;font-size:12px;line-height:1.6;color:#a1a1aa;">如果按钮无法点击，请复制以下链接到浏览器打开：</p>'
                    .'<p style="margin:6px 0 0;font-size:12px;line-height:1.5;color:#71717a;word-break:break-all;">{{link}}</p>'
                    .'<p style="margin:20px 0 0;font-size:12px;color:#a1a1aa;">如果这不是您本人的操作，请忽略此邮件。</p>'
                ),
            ],
            EmailTemplate::KEY_SITE_NOTICE => [
                'subject' => '站点通知 - {{name}}',
                'content' => self::shell(
                    '站点通知',
                    '<p style="margin:0 0 24px;font-size:14px;line-height:1.7;color:#52525b;white-space:pre-line;">{{content}}</p>'
                    .self::button('前往站点', '{{url}}')
                ),
            ],
            EmailTemplate::KEY_EXPIRE_REMINDER => [
                'subject' => '订阅即将到期 - {{name}}',
                'content' => self::shell(
                    '订阅即将到期',
                    '<p style="margin:0 0 20px;font-size:14px;line-height:1.65;color:#52525b;">您的套餐即将到期，到期后该线路会停止服务。为避免中断，请提前续费。</p>'
                    .self::infoCard(['套餐' => '{{plan_name}}', '到期时间' => '{{expired_at}}'])
                    .self::button('立即续费', '{{url}}')
                ),
            ],
            EmailTemplate::KEY_TRAFFIC_REMINDER => [
                'subject' => '流量即将用完 - {{name}}',
                'content' => self::shell(
                    '流量即将用完',
                    '<p style="margin:0 0 20px;font-size:14px;line-height:1.65;color:#52525b;">您的套餐流量即将用完，用完后该线路会限速或停止服务。</p>'
                    .self::infoCard(['套餐' => '{{plan_name}}', '已用流量' => '{{percent}}%'])
                    .self::button('查看用量', '{{url}}')
                ),
            ],
            default => ['subject' => '', 'content' => ''],
        };
    }

    private static function shell(string $heading, string $body): string
    {
        return <<<HTML
        <!DOCTYPE html>
        <html lang="zh-CN">
        <body style="margin:0;padding:0;background-color:#f4f4f5;">
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f5;padding:40px 16px;">
            <tr><td align="center">
              <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="width:100%;max-width:480px;background-color:#ffffff;border:1px solid #e4e4e7;border-radius:12px;overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;">
                <tr><td style="padding:26px 32px 18px;border-bottom:1px solid #f1f1f3;">
                  <span style="font-size:18px;font-weight:700;color:#18181b;">{{name}}</span>
                </td></tr>
                <tr><td style="padding:28px 32px 32px;">
                  <h1 style="margin:0 0 12px;font-size:20px;font-weight:700;color:#18181b;">{$heading}</h1>
                  {$body}
                </td></tr>
                <tr><td style="padding:18px 32px;border-top:1px solid #f1f1f3;background-color:#fafafa;">
                  <a href="{{url}}" style="font-size:12px;color:#a1a1aa;text-decoration:none;">{{url}}</a>
                </td></tr>
              </table>
            </td></tr>
          </table>
        </body>
        </html>
        HTML;
    }

    private static function button(string $label, string $href): string
    {
        return '<table role="presentation" cellpadding="0" cellspacing="0"><tr>'
            .'<td style="border-radius:8px;background-color:#0d9488;">'
            .'<a href="'.$href.'" style="display:inline-block;padding:12px 30px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;">'.$label.'</a>'
            .'</td></tr></table>';
    }

    /**
     * @param  array<string,string>  $rows  label => value (value may itself be a {{token}})
     */
    private static function infoCard(array $rows): string
    {
        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;border:1px solid #f1f1f3;border-radius:8px;background-color:#fafafa;">';
        $labels = array_keys($rows);
        $last = array_key_last($labels);
        foreach ($labels as $i => $label) {
            $border = $i === $last ? '' : 'border-bottom:1px solid #f1f1f3;';
            $html .= '<tr>'
                .'<td style="padding:12px 16px;font-size:13px;color:#71717a;'.$border.'">'.$label.'</td>'
                .'<td style="padding:12px 16px;font-size:13px;color:#18181b;font-weight:600;text-align:right;'.$border.'">'.$rows[$label].'</td>'
                .'</tr>';
        }

        return $html.'</table>';
    }
}
