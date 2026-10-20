<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\RegistrationGuard;
use App\Services\WebAbuseLimiter;
use App\Support\SiteKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * RateLimiter resolves its cache store once, before tenancy is initialized, so
 * — unlike the Cache facade — it never picks up the per-tenant prefix and all
 * sites share one set of counters. Keys built from an email or a user id then
 * refer to different people depending on the site, so they must carry the site.
 */
class RateLimitPerSiteTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenant()) {
            tenancy()->end();
        }
        foreach (Tenant::all() as $t) {
            $t->delete();
        }
        parent::tearDown();
    }

    public function test_the_same_email_in_two_sites_does_not_share_a_lockout(): void
    {
        // Same address, two unrelated people. Someone hammering the 主站 account
        // must not lock the 分站 one out.
        $email = 'me@qq.com';
        User::create(['name' => '主站的人', 'email' => $email, 'password' => 'central-pass']);

        $t = Tenant::create(['id' => 'lockshop']);
        $t->domains()->create(['domain' => 'lockshop']);
        $t->run(fn () => User::create(['name' => '分站的人', 'email' => $email, 'password' => 'tenant-pass']));

        // Burn through the 主站 lockout with wrong passwords.
        for ($i = 0; $i < 6; $i++) {
            Livewire::test('auth.login')->set('email', $email)->set('password', 'wrong')->call('login');
        }
        $this->assertFalse(auth()->check());

        // The 分站 account is untouched and can still log in.
        $t->run(function () use ($email) {
            Livewire::test('auth.login')
                ->set('email', $email)
                ->set('password', 'tenant-pass')
                ->call('login')
                ->assertSet('error', '');

            $this->assertTrue(auth()->check());
            $this->assertSame('分站的人', auth()->user()->name);
        });
    }

    public function test_registration_ip_limits_are_counted_per_site(): void
    {
        // One shop being spammed from an IP shouldn't stop another shop's
        // signups from that same IP.
        $ip = '198.51.100.7';
        $guard = app(RegistrationGuard::class);

        $t = Tenant::create(['id' => 'ipshop']);
        $t->run(function () use ($guard, $ip) {
            for ($i = 0; $i < 10; $i++) {
                $guard->recordRegistration($ip);
            }
        });

        $this->assertSame(
            0,
            RateLimiter::attempts(SiteKey::scope('register:ip:'.$ip)),
            '主站不该继承分站的注册计数'
        );
    }

    public function test_fixed_registration_floor_is_counted_per_site(): void
    {
        $ip = '198.51.100.8';
        $limiter = app(WebAbuseLimiter::class);
        $tenant = Tenant::create(['id' => 'fixedreg']);

        $tenant->run(function () use ($limiter, $ip) {
            for ($i = 0; $i < WebAbuseLimiter::REGISTRATION_IP_MAX; $i++) {
                $limiter->recordRegistrationAttempt($ip);
            }

            $this->assertGreaterThan(0, $limiter->registrationRetryAfter($ip));
        });

        $this->assertSame(0, $limiter->registrationRetryAfter($ip));
    }

    public function test_ticket_limits_are_counted_per_site_when_user_ids_collide(): void
    {
        $limiter = app(WebAbuseLimiter::class);
        $tenant = Tenant::create(['id' => 'ticketlimit']);

        $tenant->run(function () use ($limiter) {
            for ($i = 0; $i < WebAbuseLimiter::TICKET_CREATE_USER_MAX; $i++) {
                $limiter->recordTicketCreate(1, '198.51.100.9');
            }

            $this->assertGreaterThan(0, $limiter->ticketCreateRetryAfter(1, '198.51.100.9'));
        });

        $this->assertSame(0, $limiter->ticketCreateRetryAfter(1, '198.51.100.9'));
    }

    public function test_keys_are_namespaced_per_site_and_left_alone_on_the_main_site(): void
    {
        $this->assertSame('login:a@b.com', SiteKey::scope('login:a@b.com'));

        $t = Tenant::create(['id' => 'nskey']);
        $this->assertSame(
            'site:nskey:login:a@b.com',
            $t->run(fn () => SiteKey::scope('login:a@b.com'))
        );
    }

    public function test_colliding_user_ids_do_not_share_a_two_factor_lockout(): void
    {
        // Every 分站 numbers its users from 1, so an id-keyed limiter collides
        // with 主站's by definition.
        $t = Tenant::create(['id' => 'tfa']);

        $central = SiteKey::scope('admin-2fa:1');
        $tenantKey = $t->run(fn () => SiteKey::scope('admin-2fa:1'));

        $this->assertNotSame($central, $tenantKey);
    }
}
