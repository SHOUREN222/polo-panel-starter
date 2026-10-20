<?php

namespace App\Services;

use App\Mail\TemplatedMail;
use App\Models\EmailTemplate;

/**
 * Renders an admin-customized email template if one has been saved for the
 * given key; returns null when the admin never touched it, so callers fall
 * back to their own hardcoded MailMessage-based default. This is the same
 * "real but optional override" pattern as the SMTP settings — nothing here
 * is fake, it's just inert until an admin actually fills it in.
 */
class EmailTemplateService
{
    /**
     * @param  array<string,string>  $tokens  values keyed without braces, e.g. ['link' => '...']
     * @param  object|null  $notifiable  the notification recipient — REQUIRED for
     *   notifications. A notification that returns a Mailable (this) does NOT get
     *   its recipient injected by Laravel the way a MailMessage does, so if we
     *   don't set the "To" here the send throws "An email must have a To header"
     *   and the mail silently never goes out. Passing the notifiable fixes it.
     */
    public function render(string $key, array $tokens, ?object $notifiable = null): ?TemplatedMail
    {
        $template = EmailTemplate::where('key', $key)->first();

        if (! $template) {
            return null;
        }

        $subject = $this->substitute($template->subject, $tokens);
        $content = $this->substitute($template->content, $tokens);

        $mail = new TemplatedMail($subject, $content);

        if ($notifiable !== null && ($to = $this->recipientFor($notifiable))) {
            $mail->to($to);
        }

        return $mail;
    }

    /** Resolve the mail address for a notifiable (User model, or an on-demand mail route). */
    private function recipientFor(object $notifiable): ?string
    {
        if (method_exists($notifiable, 'routeNotificationFor')) {
            $route = $notifiable->routeNotificationFor('mail');

            return is_array($route) ? (array_key_first($route) ?: null) : ($route ?: null);
        }

        return $notifiable->email ?? null;
    }

    private function substitute(string $text, array $tokens): string
    {
        $replacements = [];
        foreach ($tokens as $key => $value) {
            $replacements['{{'.$key.'}}'] = $value;
        }

        return strtr($text, $replacements);
    }
}
