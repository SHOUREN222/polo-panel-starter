<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\SiteKey;
use Illuminate\Support\Facades\RateLimiter;

class WebAbuseLimiter
{
    public const LOGIN_ACCOUNT_MAX = 5;

    public const LOGIN_IP_MAX = 20;

    public const REGISTRATION_IP_MAX = 10;

    public const TICKET_CREATE_USER_MAX = 5;

    public const TICKET_CREATE_IP_MAX = 20;

    public const TICKET_REPLY_USER_MAX = 30;

    public const TICKET_REPLY_IP_MAX = 100;

    public const MAGIC_LOGIN_EMAIL_MAX = 3;

    public const MAGIC_LOGIN_IP_MAX = 10;

    public const PASSWORD_RESET_IP_MAX = 10;

    public const VERIFY_EMAIL_USER_MAX = 3;

    public const VERIFY_EMAIL_IP_MAX = 10;

    public function loginRetryAfter(string $email, string $ip): int
    {
        $limits = [
            $this->loginAccountKey($email, $ip) => self::LOGIN_ACCOUNT_MAX,
            $this->loginIpKey($ip) => self::LOGIN_IP_MAX,
        ];

        if (Setting::getBool('login_attempt_limit_enabled', true)) {
            $limits[$this->legacyLoginKey($email)] = max(1, (int) Setting::get('login_max_attempts', 5));
        }

        return $this->retryAfter($limits);
    }

    public function recordLoginFailure(string $email, string $ip): void
    {
        RateLimiter::hit($this->loginAccountKey($email, $ip), 60);
        RateLimiter::hit($this->loginIpKey($ip), 60);

        if (Setting::getBool('login_attempt_limit_enabled', true)) {
            $decay = max(1, (int) Setting::get('login_lockout_minutes', 60)) * 60;
            RateLimiter::hit($this->legacyLoginKey($email), $decay);
        }
    }

    public function clearLoginAccount(string $email, string $ip): void
    {
        RateLimiter::clear($this->loginAccountKey($email, $ip));
        RateLimiter::clear($this->legacyLoginKey($email));
    }

    public function registrationRetryAfter(string $ip): int
    {
        return $this->retryAfter([
            $this->registrationIpKey($ip) => self::REGISTRATION_IP_MAX,
        ]);
    }

    public function recordRegistrationAttempt(string $ip): void
    {
        RateLimiter::hit($this->registrationIpKey($ip), 3600);
    }

    public function ticketCreateRetryAfter(int $userId, string $ip): int
    {
        return $this->retryAfter([
            $this->ticketCreateUserKey($userId) => self::TICKET_CREATE_USER_MAX,
            $this->ticketCreateIpKey($ip) => self::TICKET_CREATE_IP_MAX,
        ]);
    }

    public function recordTicketCreate(int $userId, string $ip): void
    {
        RateLimiter::hit($this->ticketCreateUserKey($userId), 3600);
        RateLimiter::hit($this->ticketCreateIpKey($ip), 3600);
    }

    public function ticketReplyRetryAfter(int $userId, string $ip): int
    {
        return $this->retryAfter([
            $this->ticketReplyUserKey($userId) => self::TICKET_REPLY_USER_MAX,
            $this->ticketReplyIpKey($ip) => self::TICKET_REPLY_IP_MAX,
        ]);
    }

    public function recordTicketReply(int $userId, string $ip): void
    {
        RateLimiter::hit($this->ticketReplyUserKey($userId), 3600);
        RateLimiter::hit($this->ticketReplyIpKey($ip), 3600);
    }

    public function magicLoginRetryAfter(string $email, string $ip): int
    {
        return $this->retryAfter([
            $this->magicLoginEmailKey($email) => self::MAGIC_LOGIN_EMAIL_MAX,
            $this->magicLoginIpKey($ip) => self::MAGIC_LOGIN_IP_MAX,
        ]);
    }

    public function recordMagicLoginRequest(string $email, string $ip): void
    {
        RateLimiter::hit($this->magicLoginEmailKey($email), 300);
        RateLimiter::hit($this->magicLoginIpKey($ip), 300);
    }

    public function passwordResetRetryAfter(string $ip): int
    {
        return $this->retryAfter([
            $this->passwordResetIpKey($ip) => self::PASSWORD_RESET_IP_MAX,
        ]);
    }

    public function recordPasswordResetRequest(string $ip): void
    {
        RateLimiter::hit($this->passwordResetIpKey($ip), 600);
    }

    public function verificationEmailRetryAfter(int $userId, string $ip): int
    {
        return $this->retryAfter([
            $this->verifyEmailUserKey($userId) => self::VERIFY_EMAIL_USER_MAX,
            $this->verifyEmailIpKey($ip) => self::VERIFY_EMAIL_IP_MAX,
        ]);
    }

    public function recordVerificationEmail(int $userId, string $ip): void
    {
        RateLimiter::hit($this->verifyEmailUserKey($userId), 600);
        RateLimiter::hit($this->verifyEmailIpKey($ip), 600);
    }

    private function retryAfter(array $limits): int
    {
        $retryAfter = 0;

        foreach ($limits as $key => $maxAttempts) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                $retryAfter = max($retryAfter, RateLimiter::availableIn($key));
            }
        }

        return $retryAfter;
    }

    private function loginAccountKey(string $email, string $ip): string
    {
        return SiteKey::scope('login-acct:'.$this->normalizeEmail($email).'|'.$ip);
    }

    private function loginIpKey(string $ip): string
    {
        return SiteKey::scope('login-ip:'.$ip);
    }

    private function legacyLoginKey(string $email): string
    {
        return SiteKey::scope('login:'.$this->normalizeEmail($email));
    }

    private function registrationIpKey(string $ip): string
    {
        return SiteKey::scope('register-ip:'.$ip);
    }

    private function ticketCreateUserKey(int $userId): string
    {
        return SiteKey::scope('ticket-create-user:'.$userId);
    }

    private function ticketCreateIpKey(string $ip): string
    {
        return SiteKey::scope('ticket-create-ip:'.$ip);
    }

    private function ticketReplyUserKey(int $userId): string
    {
        return SiteKey::scope('ticket-reply-user:'.$userId);
    }

    private function ticketReplyIpKey(string $ip): string
    {
        return SiteKey::scope('ticket-reply-ip:'.$ip);
    }

    private function magicLoginEmailKey(string $email): string
    {
        return SiteKey::scope('magic-login:'.$this->normalizeEmail($email));
    }

    private function magicLoginIpKey(string $ip): string
    {
        return SiteKey::scope('magic-login-ip:'.$ip);
    }

    private function passwordResetIpKey(string $ip): string
    {
        return SiteKey::scope('password-reset-ip:'.$ip);
    }

    private function verifyEmailUserKey(int $userId): string
    {
        return SiteKey::scope('verify-email-user:'.$userId);
    }

    private function verifyEmailIpKey(string $ip): string
    {
        return SiteKey::scope('verify-email-ip:'.$ip);
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}
