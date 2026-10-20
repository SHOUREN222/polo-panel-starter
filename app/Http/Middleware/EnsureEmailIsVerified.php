<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the customer-facing app when 系统设置 → 安全设置 → 邮箱验证 is on —
 * admins are exempt (they log in through a separate flow entirely and this
 * setting is about customer signup hygiene, not admin access).
 */
class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->is_admin) {
            return $next($request);
        }

        if (! Setting::getBool('email_verification_enabled', false)) {
            return $next($request);
        }

        if ($user->email_verified_at !== null) {
            return $next($request);
        }

        if ($request->routeIs('verify-notice') || $request->routeIs('verify-email') || $request->routeIs('logout')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'fail',
                'message' => '请先验证邮箱后再继续操作',
            ], 403);
        }

        return redirect()->route('verify-notice');
    }
}
