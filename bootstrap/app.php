<?php

use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsureSafeModeDomain;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'admin' => EnsureUserIsAdmin::class,
            'verified-email' => EnsureEmailIsVerified::class,
        ]);

        // Laravel 12's api middleware group carries no throttle by default —
        // add one so every /api/* route has a per-caller ceiling (see the
        // 'api' limiter in AppServiceProvider). login/register stack a tighter
        // limiter on top in routes/api.php.
        $middleware->api(append: ['throttle:api']);

        // Behind Caddy (which terminates TLS on 443 and reverse-proxies to
        // nginx on 127.0.0.1:8080), trust only the loopback hop so Laravel
        // honours Caddy's X-Forwarded-Proto/Host and generates https:// URLs.
        // Direct public hits to :8080 come from real IPs — never trusted —
        // so an attacker can't spoof https by forging the header.
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);

        $middleware->web(prepend: [EnsureSafeModeDomain::class]);
        $middleware->web(append: [
            EnsureUserIsActive::class,
            EnsureEmailIsVerified::class,
        ]);

        // Multi-tenant: identify a 分站 by its subdomain BEFORE StartSession so
        // sessions/auth land in the tenant DB. Marked 'universal' so on central
        // domains the identifier continues-as-central instead of throwing (the
        // main 主站 keeps working exactly as before). See Features\UniversalRoutes.
        $middleware->group('universal', []);
        $middleware->prependToGroup('web', InitializeTenancyBySubdomain::class);
        $middleware->appendToGroup('web', 'universal');

        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is(config('admin.path'), config('admin.path').'/*')
                ? route('admin.login')
                : route('login'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
