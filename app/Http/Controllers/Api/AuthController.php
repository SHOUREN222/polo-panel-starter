<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\VerifyEmailAddress;
use App\Services\RegistrationGuard;
use App\Services\UserAccessRevoker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function register(Request $request, RegistrationGuard $guard)
    {
        if (Setting::getBool('stop_register')) {
            return response()->json([
                'status' => 'fail',
                'message' => '当前站点已停止新用户注册',
            ], 403);
        }

        // Same admin-configurable IP register limit the web form enforces, so
        // the API isn't a way around 安全设置 → IP 注册限制. (The throttle:register
        // middleware is the always-on floor on top of this opt-in check.)
        $ip = $request->ip();
        if ($guard->ipLimitExceeded($ip)) {
            return response()->json([
                'status' => 'fail',
                'message' => '注册过于频繁，请稍后再试',
            ], 429);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::min(8)],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        $guard->recordRegistration($ip);

        if (Setting::getBool('email_verification_enabled', false)) {
            $user->notify(new VerifyEmailAddress);
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'data' => [
                'user' => $this->present($user),
                'token' => $token,
            ],
        ], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        // Emails are stored lowercased (User::email()) — normalize the input so
        // a capitalized address still authenticates.
        $credentials = ['email' => strtolower(trim($data['email'])), 'password' => $data['password']];
        if (! Auth::validate($credentials)) {
            return response()->json([
                'status' => 'fail',
                'message' => '邮箱或密码错误',
            ], 401);
        }

        /** @var User|null $user */
        $user = User::where('email', $credentials['email'])->first();

        if (! $user) {
            return response()->json([
                'status' => 'fail',
                'message' => '邮箱或密码错误',
            ], 401);
        }

        if ($user->banned) {
            app(UserAccessRevoker::class)->revoke($user);

            return response()->json([
                'status' => 'fail',
                'message' => '账号已被封禁',
            ], 403);
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'status' => 'success',
            'data' => [
                'user' => $this->present($user),
                'token' => $token,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['status' => 'success']);
    }

    public function me(Request $request)
    {
        return response()->json([
            'status' => 'success',
            'data' => $this->present($request->user()),
        ]);
    }

    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->email_verified_at !== null,
            'uuid' => $user->uuid,
            'balance' => $user->balance,
        ];
    }
}
