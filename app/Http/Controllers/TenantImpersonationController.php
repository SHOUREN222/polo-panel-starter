<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\AdminSession;
use App\Support\TenantImpersonationToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * The tenant-subdomain half of "进入分站后台" (see ⚡tenants.blade.php's
 * enterTenant()). Only reachable with a valid short-lived signed token —
 * there is no password/2FA prompt here by design, the proof already
 * happened on the central side (站长's own already-2FA'd admin session).
 */
class TenantImpersonationController extends Controller
{
    public function enter(Request $request): RedirectResponse
    {
        abort_unless(tenant(), 404);

        $claims = TenantImpersonationToken::verify($request);
        abort_unless($claims && $claims['tenantId'] === tenant()->getTenantKey(), 403);

        $user = User::find($claims['userId']);
        abort_unless($user && $user->isAdmin() && ! $user->banned, 403);

        Auth::login($user);
        $request->session()->regenerate();

        // Satisfies EnsureUserIsAdmin's post-login session-freshness check
        // without requiring this 分站 admin's own 2FA — see the
        // tenant_impersonation exemption in EnsureUserIsAdmin.
        AdminSession::markVerified($user, true);

        $request->session()->put('tenant_impersonation', [
            'super_admin_id' => $claims['superAdminId'],
            'started_at' => now()->timestamp,
        ]);

        Log::info('Super admin entered a 分站 admin panel via impersonation', [
            'super_admin_id' => $claims['superAdminId'],
            'tenant_id' => tenant()->getTenantKey(),
            'impersonated_user_id' => $user->id,
        ]);

        return redirect()->route('admin.dashboard')->with('status', '已进入分站后台');
    }

    public function exit(Request $request): RedirectResponse
    {
        abort_unless(tenant(), 404);

        $request->session()->forget('tenant_impersonation');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->away(rtrim((string) config('app.url'), '/').'/'.config('admin.path').'/tenants');
    }
}
