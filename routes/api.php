<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CaddyOnDemandController;
use App\Http\Controllers\Api\InstanceController;
use App\Http\Controllers\Api\NodeConfigController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\ServerController;
use App\Http\Controllers\Api\ServerHeartbeatController;
use App\Http\Controllers\Api\TrafficReportController;
use App\Http\Controllers\Api\UniProxyController;
use App\Http\Controllers\GatewayPaymentController;
use App\Http\Controllers\OnipCallbackController;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;

/*
|--------------------------------------------------------------------------
| Customer API (主站 + 分站)
|--------------------------------------------------------------------------
|
| These routes operate on customer-owned data, so a registered subdomain must
| initialize its tenant before auth, validation, or model binding runs. The
| universal marker lets central domains continue without a tenant. Keep node,
| traffic, Caddy, and provider callbacks outside this group: those aggregate or
| manage platform-wide infrastructure and must always start in central context.
|
*/
Route::middleware(['universal', InitializeTenancyBySubdomain::class])->group(function () {
    // Auth endpoints stack a tighter limiter on top of the group-wide 'api' one:
    // 'login' matches the web login's per-account lockout, 'register' the
    // RegistrationGuard IP floor — so neither can be bypassed by hitting the API.
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::get('/plans', [PlanController::class, 'index']);

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        Route::middleware('verified-email')->group(function () {
            Route::get('/orders', [OrderController::class, 'index']);
            Route::post('/orders', [OrderController::class, 'store']);
            Route::post('/orders/{tradeNo}/pay', [OrderController::class, 'pay']);

            Route::get('/servers', [ServerController::class, 'index']);
            Route::get('/instances', [InstanceController::class, 'index']);
        });
    });
});

// Platform infrastructure API. These endpoints deliberately remain central,
// including when called through a registered 分站 hostname.
Route::post('/servers/heartbeat', [ServerHeartbeatController::class, 'store']);
Route::post('/traffic/report', [TrafficReportController::class, 'store']);
Route::get('/nodes/config', [NodeConfigController::class, 'show']);
Route::any('/payment/{payment}/notify', [GatewayPaymentController::class, 'notify'])->name('gateway.notify');
Route::any('/epay/notify', [GatewayPaymentController::class, 'legacyEpayNotify'])->name('epay.notify');
Route::post('/callbacks/onip', OnipCallbackController::class)->name('onip.callback');

// Caddy on-demand TLS gate: only sign certs for the main site + registered 分站
// subdomains. See the controller docblock. Read-only, fails closed.
Route::get('/caddy-ondemand-ask', CaddyOnDemandController::class)->name('caddy.ondemand.ask');

// V2bX's "UniProxy" node-agent protocol — see UniProxyController's class
// docblock. Path shape (v1/server/UniProxy/*) is fixed by V2bX itself, not
// a convention of this app.
Route::prefix('v1/server/UniProxy')->group(function () {
    Route::get('/config', [UniProxyController::class, 'config']);
    Route::get('/user', [UniProxyController::class, 'user']);
    Route::post('/push', [UniProxyController::class, 'push']);
    Route::get('/alivelist', [UniProxyController::class, 'aliveList']);
    Route::post('/alive', [UniProxyController::class, 'alive']);
});
