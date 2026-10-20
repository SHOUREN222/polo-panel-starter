<?php

use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\GatewayPaymentController;
use App\Http\Controllers\MagicLoginController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TenantImpersonationController;
use App\Models\Setting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// The path segment is admin-configurable (系统设置 → 订阅设置 → 订阅路径),
// same caveat as Xboard's own version: changing it requires the app to
// reboot/reload routes to take effect, since routes are registered once.
try {
    $subscribePath = Setting::get('subscribe_path', 'subscribe') ?: 'subscribe';
} catch (Throwable) {
    $subscribePath = 'subscribe';
}

Route::get('/'.$subscribePath.'/{token}', [SubscriptionController::class, 'show'])->name('subscribe');
Route::get('/'.$subscribePath.'/{token}/shared', [SubscriptionController::class, 'showShared'])->name('subscribe.shared');
Route::get('/'.$subscribePath.'/{token}/instance/{instance}', [SubscriptionController::class, 'showInstance'])->name('subscribe.instance');

Route::get('/pay/{payment}/{order}', [GatewayPaymentController::class, 'pay'])->middleware('auth')->name('gateway.pay');

Route::livewire('/login', 'auth.login')->middleware('guest')->name('login');
Route::livewire('/register', 'auth.register')->middleware('guest')->name('register');
Route::livewire('/forgot-password', 'auth.forgot-password')->middleware('guest')->name('password.request');
Route::livewire('/reset-password/{token}', 'auth.reset-password')->middleware('guest')->name('password.reset');
Route::livewire('/verify-notice', 'auth.verify-notice')->middleware('auth')->name('verify-notice');
Route::get('/verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])->middleware(['auth', 'signed'])->name('verify-email');
Route::livewire('/login-by-email', 'auth.login-by-email')->middleware('guest')->name('login.email');
Route::get('/login-via-email/{id}', [MagicLoginController::class, 'login'])->middleware(['guest', 'signed'])->name('login-via-email');

Route::post('/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect('/login');
})->middleware('auth')->name('logout');

Route::livewire('/', 'dashboard')->middleware('auth')->name('dashboard');
Route::livewire('/plans', 'plans')->middleware('auth')->name('plans');
Route::livewire('/renew', 'renew')->middleware('auth')->name('renew');
Route::livewire('/orders', 'orders')->middleware('auth')->name('orders');
Route::livewire('/invite', 'invite')->middleware('auth')->name('invite');
Route::livewire('/knowledge', 'knowledge')->middleware('auth')->name('knowledge');
Route::livewire('/tickets', 'tickets')->middleware('auth')->name('tickets');
Route::livewire('/profile', 'profile')->middleware('auth')->name('profile');
Route::livewire('/traffic', 'traffic')->middleware('auth')->name('traffic');
Route::livewire('/agent', 'agent')->middleware('auth')->name('agent');

// The admin panel lives behind a non-obvious, per-install path (see
// config/admin.php) instead of the guessable "/admin" — login is a
// separate page from the customer-facing one and only accepts admin
// accounts, matching the pattern of the reference Xboard install.
// 站长"进入分站后台"的落地点（见 ⚡tenants.blade.php enterTenant()）。只认签名
// 短效token,不在 admin.path 前缀下——这条路由本身就是登录动作,不能要求先登录。
Route::get('/__enter-tenant-admin', [TenantImpersonationController::class, 'enter'])->name('tenant.impersonation.enter');

Route::prefix(config('admin.path'))->group(function () {
    Route::livewire('/login', 'admin.login')->middleware('guest')->name('admin.login');

    Route::post('/impersonation/exit', [TenantImpersonationController::class, 'exit'])
        ->middleware('auth')->name('tenant.impersonation.exit');

    // Second step of admin login: reached only with a pending-2FA session
    // marker (set after the password checks out). Guest middleware — the user
    // isn't authenticated until the code is verified here.
    Route::livewire('/2fa', 'admin.two-factor-challenge')->middleware('guest')->name('admin.2fa');

    Route::post('/logout', function () {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return redirect()->route('admin.login');
    })->middleware('auth')->name('admin.logout');

    Route::middleware(['auth', 'admin'])->group(function () {
        Route::livewire('/', 'admin.dashboard')->name('admin.dashboard');

        // 品牌（分站可自定义；主站也可用）
        Route::livewire('/branding', 'admin.branding')->name('admin.branding');

        // 系统管理
        Route::livewire('/system', 'admin.system')->name('admin.system');
        Route::livewire('/plugins', 'admin.plugins')->name('admin.plugins');
        Route::livewire('/theme', 'admin.theme')->name('admin.theme');
        Route::livewire('/notices', 'admin.notices')->name('admin.notices');
        Route::livewire('/payments', 'admin.payments')->name('admin.payments');
        Route::livewire('/knowledge', 'admin.knowledge')->name('admin.knowledge');

        // 节点管理
        Route::livewire('/servers', 'admin.servers')->name('admin.servers');
        Route::livewire('/nodes', 'admin.nodes')->name('admin.nodes');
        Route::livewire('/groups', 'admin.groups')->name('admin.groups');
        Route::livewire('/routing', 'admin.routing')->name('admin.routing');

        // 订阅管理
        Route::livewire('/plans', 'admin.plans')->name('admin.plans');
        Route::livewire('/orders', 'admin.orders')->name('admin.orders');
        Route::livewire('/coupons', 'admin.coupons')->name('admin.coupons');
        Route::livewire('/giftcards', 'admin.giftcards')->name('admin.giftcards');
        Route::livewire('/wholesale', 'admin.wholesale')->name('admin.wholesale');

        // 分站（多租户）
        Route::livewire('/tenants', 'admin.tenants')->name('admin.tenants');

        // 用户管理
        Route::livewire('/users', 'admin.users')->name('admin.users');
        Route::livewire('/tickets', 'admin.tickets')->name('admin.tickets');
        Route::livewire('/invites', 'admin.invites')->name('admin.invites');
        Route::livewire('/profile', 'admin.profile')->name('admin.profile');
    });
});
