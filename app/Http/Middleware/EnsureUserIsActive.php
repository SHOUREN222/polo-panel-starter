<?php

namespace App\Http\Middleware;

use App\Services\UserAccessRevoker;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user()?->fresh();

        if (! $user?->banned) {
            return $next($request);
        }

        app(UserAccessRevoker::class)->revoke($user);

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'status' => 'fail',
                'message' => '账号已被封禁',
            ], 403);
        }

        $route = $user->is_admin ? 'admin.login' : 'login';
        $messageKey = $user->is_admin ? 'admin_auth_error' : 'auth_error';

        return redirect()->route($route)->with($messageKey, '账号已被封禁');
    }
}
