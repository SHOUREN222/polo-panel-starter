<?php

namespace App\Http\Middleware;

use App\Support\AdminSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Refresh on every backend request so a ban, demotion, or role change
        // revokes an already-open session immediately, even in a long-lived
        // worker where the auth guard may still hold an older model instance.
        $user = $request->user()?->fresh();

        if ($user?->banned) {
            return $this->rejectSession($request, '账号已被封禁');
        }

        if (! $user?->isAdmin()) {
            abort(403);
        }

        // Role gate: a 客服 (support) admin may only open whitelisted sections;
        // 超级管理员 passes everything. Keyed off the route name so it stays in
        // lockstep with the sidebar, which reads the same canAccessAdminRoute().
        if (! $user->canAccessAdminRoute($request->route()?->getName())) {
            abort(403);
        }

        if (config('admin.secure_session', true) && ! AdminSession::isVerified($user)) {
            return $this->rejectSession($request, '后台登录状态已失效，请重新验证');
        }

        // A 站长 impersonating a 分站 admin (see TenantImpersonationController)
        // already proved their identity with their own central 2FA before
        // this session started — that 分站 admin's own account may not have
        // 2FA configured at all, and forcing them to set it up here would be
        // asking the visiting 站长 to configure a stranger's account.
        $impersonating = $request->session()->has('tenant_impersonation');

        if (config('admin.require_two_factor', true)
            && ! $user->hasTwoFactorEnabled()
            && ! $impersonating
            && $request->route()?->getName() !== 'admin.profile') {
            return redirect()->route('admin.profile')
                ->with('admin_2fa_setup_required', '后台账号必须开启两步验证后才能继续操作');
        }

        if (config('admin.secure_session', true)
            && in_array($request->route()?->getName(), config('admin.sensitive_routes', []), true)
            && ! AdminSession::isRecentlyVerified($user)) {
            return $this->rejectSession($request, '该操作需要重新验证密码和两步验证码');
        }

        return $next($request);
    }

    private function rejectSession(Request $request, string $message): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->with('admin_auth_error', $message);
    }
}
