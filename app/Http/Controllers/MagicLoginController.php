<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MagicLoginController extends Controller
{
    /**
     * The signed-URL check (route middleware) already proves this link was
     * genuinely issued by us and hasn't expired or been tampered with — no
     * separate token/hash column needed, unlike password-reset which has
     * to survive being looked up by an untrusted value.
     */
    public function login(Request $request, int $id)
    {
        // If disabled after a link was already sent, that link should stop
        // working immediately — not just hide the request form.
        abort_unless(Setting::getBool('magic_login_enabled', true), 404);

        $user = User::findOrFail($id);

        if ($user->banned) {
            abort(403, '账号已被封禁');
        }
        if ($user->isAdmin()) {
            abort(403, '管理员请使用后台登录入口');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/')->with('status', '登录成功');
    }
}
