<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;

/**
 * Sends an admin-authored email template (系统设置 → 邮件设置 → 模板管理)
 * as raw HTML — unlike the default MailMessage-based notifications, there's
 * no markdown/line-builder structure here, just whatever HTML the admin
 * saved, with {{tokens}} already substituted by EmailTemplateService.
 */
class TemplatedMail extends Mailable
{
    use Queueable;

    public function __construct(public string $emailSubject, public string $htmlContent)
    {
    }

    public function build(): self
    {
        return $this->subject($this->emailSubject)->html($this->htmlContent);
    }
}
