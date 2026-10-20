<?php

namespace App\Providers;

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\Setting;
use App\Notifications\AdminLoginAlert;
use App\Services\Provisioners\OnipProvisioner;
use App\Services\Provisioners\ProvisionerInterface;
use App\Services\Provisioners\StubProvisioner;
use App\Support\SiteKey;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Stancl\Tenancy\Events\TenancyBootstrapped;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 节点配置 → provisioner_driver picks stub vs the real onip.cc
        // integration. Defaults to stub so tests/local dev never place a
        // real order unless an admin explicitly opts in after configuring
        // credentials.
        $this->app->bind(ProvisionerInterface::class, function ($app) {
            return Setting::get('provisioner_driver', 'stub') === 'onip'
                ? $app->make(OnipProvisioner::class)
                : $app->make(StubProvisioner::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Livewire update requests hit a shared endpoint instead of the original
        // admin route. Persist this gate so every component action re-checks the
        // original route's current admin role, ban state, and 2FA session proof.
        Livewire::addPersistentMiddleware(EnsureUserIsAdmin::class);

        $this->configureRateLimiters();

        // Heartbeat for the admin dashboard's 队列状态 card — no Horizon/Redis
        // in this stack, so this is the only honest signal we have that a
        // queue worker is actually alive and processing jobs, rather than
        // just "the jobs table has rows in it."
        Queue::after(function (JobProcessed $event) {
            Setting::set('queue_worker_last_seen_at', now()->toDateTimeString());
        });

        // Record last-login time + IP on every successful auth (web, admin,
        // magic-link, API — all go through Auth::attempt/login, which fires
        // this event). saveQuietly so it never touches updated_at or fires
        // model observers; wrapped defensively so a login is never blocked by
        // a tracking write (e.g. before the columns exist during migrate).
        // For admins, a login from a new IP also fires a Telegram alert so a
        // compromised admin session can't happen silently.
        Event::listen(function (Login $event) {
            try {
                $user = $event->user;
                $previousIp = $user->last_login_ip;
                $ip = request()->ip();

                $user->forceFill([
                    'last_login_at' => now(),
                    'last_login_ip' => $ip,
                ])->saveQuietly();

                if ($user->isAdmin() && $previousIp !== $ip) {
                    $user->notify(new AdminLoginAlert(
                        ip: (string) $ip,
                        at: now()->format('Y-m-d H:i:s'),
                        firstLogin: $previousIp === null,
                    ));
                }
            } catch (\Throwable) {
                //
            }
        });

        // 系统设置 → 安全设置 → 后台路径 is admin-UI-editable, but routes are
        // registered once at boot — same "needs a restart to take effect"
        // caveat Xboard itself documents for this exact field. This runs
        // before routes/web.php reads config('admin.path'), since providers
        // boot before routes are lazily loaded in Laravel 12's bootstrap
        // flow. Guarded broadly because this runs on *every* request
        // including `artisan migrate` itself, before the settings table
        // necessarily exists.
        try {
            if ($adminPath = Setting::get('admin_path')) {
                config(['admin.path' => $adminPath]);
            }
        } catch (\Throwable) {
            //
        }

        // The sender NAME comes from site_name, which a 分站 overrides — but this
        // provider boots *before* the subdomain middleware initializes tenancy,
        // so at this point it can only ever see the platform's value. Re-apply
        // it once a tenant is in scope (web requests and queued jobs alike),
        // otherwise an agent's customers get mail signed with the platform's
        // name. Delivery itself deliberately stays on the platform's SMTP: one
        // warmed-up, correctly-authenticated domain beats N agents configuring
        // their own badly and silently losing their customers' verification mail.
        // TenancyBootstrapped, not TenancyInitialized: the latter fires before
        // the bootstrappers swap the DB connection, so reading a tenant setting
        // there hits the central database and blows up on a missing table.
        Event::listen(
            TenancyBootstrapped::class,
            function () {
                try {
                    config(['mail.from.name' => Setting::get('site_name', config('mail.from.name'))]);
                } catch (\Throwable) {
                    // A brand-new tenant DB may not be migrated yet; the
                    // platform name is a fine fallback and must not break the request.
                }
            }
        );

        // 系统设置 → 邮件设置 → SMTP fields, so a self-hosted buyer can
        // configure mail delivery from the admin UI instead of editing
        // .env and restarting — same override pattern as admin_path above.
        try {
            if (Setting::getBool('mail_smtp_configured', false)) {
                $scheme = match (Setting::get('mail_encryption', 'tls')) {
                    'ssl' => 'smtps',
                    default => null,
                };

                config([
                    'mail.default' => 'smtp',
                    'mail.mailers.smtp.scheme' => $scheme,
                    'mail.mailers.smtp.host' => Setting::get('mail_host'),
                    'mail.mailers.smtp.port' => (int) Setting::get('mail_port', 587),
                    'mail.mailers.smtp.username' => Setting::get('mail_username'),
                    'mail.mailers.smtp.password' => Setting::get('mail_password'),
                    'mail.from.address' => Setting::get('mail_from_address') ?: config('mail.from.address'),
                    'mail.from.name' => Setting::get('site_name', config('mail.from.name')),
                ]);
            }
        } catch (\Throwable) {
            //
        }

        ResetPassword::toMailUsing(function ($notifiable, string $token) {
            $url = url(route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], false));

            $siteName = Setting::get('site_name', config('app.name'));

            return (new MailMessage)
                ->subject("重置密码 - {$siteName}")
                ->greeting('您好')
                ->line('我们收到了重置您账户密码的请求。')
                ->action('重置密码', $url)
                ->line('此链接 60 分钟内有效。')
                ->line('如果这不是您本人的操作，可以忽略这封邮件，密码不会被更改。');
        });
    }

    /**
     * Named limiters for the API. Laravel 12 ships no throttle on the api
     * middleware group by default, so without these every /api/* endpoint —
     * including login/register and the node-agent secret checks — is
     * unlimited, letting an attacker brute-force past the web-side login
     * lockout and RegistrationGuard entirely.
     *
     *  - api:      a generous per-caller ceiling (DoS floor). Node agents poll
     *              config/user/push/report a handful of times a minute each
     *              from their own IPs, so 120/min never touches them but caps a
     *              flood. Authenticated calls bucket by user id, the rest by IP.
     *  - login:    tight, to match the web login's per-account lockout. Two
     *              buckets — 5/min per account (email+IP) stops single-account
     *              guessing; 20/min per IP stops credential-stuffing that
     *              rotates the email each request.
     *  - register: 10/hour per IP — the always-on floor under the (default-off,
     *              admin-configurable) RegistrationGuard IP limit.
     */
    private function configureRateLimiters(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by(SiteKey::scope(
                $request->user()?->id ? 'api-user:'.$request->user()->id : 'api-ip:'.$request->ip()
            )));

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(SiteKey::scope(
                'login-acct:'.strtolower((string) $request->input('email')).'|'.$request->ip()
            )),
            Limit::perMinute(20)->by(SiteKey::scope('login-ip:'.$request->ip())),
        ]);

        RateLimiter::for('register', fn (Request $request) => Limit::perHour(10)
            ->by(SiteKey::scope('register-ip:'.$request->ip())));
    }
}
