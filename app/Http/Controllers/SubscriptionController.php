<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\SubscribeTemplate;
use App\Models\SubscriptionInstance;
use App\Models\User;
use App\Services\ClientConfigRenderer;
use App\Services\SubscriptionLinkService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SubscriptionController extends Controller
{
    /**
     * Matched against the request's User-Agent header — these are the
     * real substrings each client actually sends, used to decide which
     * 订阅模板 (if any) to render instead of the plain link list.
     */
    private const CLIENT_USER_AGENT_PATTERNS = [
        SubscribeTemplate::CLIENT_CLASH_META => ['clash.meta', 'clash-verge', 'mihomo'],
        SubscribeTemplate::CLIENT_STASH => ['stash'],
        SubscribeTemplate::CLIENT_CLASH => ['clash'],
        SubscribeTemplate::CLIENT_SINGBOX => ['sing-box', 'singbox'],
        SubscribeTemplate::CLIENT_SURFBOARD => ['surfboard'],
        SubscribeTemplate::CLIENT_SURGE => ['surge'],
    ];

    public function __construct(
        private readonly SubscriptionLinkService $subscriptionLinkService,
        private readonly ClientConfigRenderer $clientConfigRenderer,
    ) {
    }

    private function detectClient(Request $request): ?string
    {
        $ua = strtolower((string) $request->userAgent());

        foreach (self::CLIENT_USER_AGENT_PATTERNS as $client => $patterns) {
            foreach ($patterns as $pattern) {
                if (str_contains($ua, $pattern)) {
                    return $client;
                }
            }
        }

        return null;
    }

    /**
     * Public, token-gated subscription endpoint — no login required. This is
     * the URL a customer pastes into their proxy client (v2rayN, Clash, ...).
     */
    public function show(string $token, Request $request): Response
    {
        if (! Setting::getBool('plugin.subscription_endpoint_enabled', true)) {
            abort(503, '订阅服务暂时不可用');
        }

        $user = User::where('token', $token)->first();

        if (! $user) {
            abort(404);
        }

        if ($user->banned) {
            abort(403);
        }

        $client = $this->detectClient($request);
        $rendered = $client ? $this->clientConfigRenderer->render($client, $user) : null;

        if ($rendered !== null) {
            $headers = ['Content-Type' => 'text/plain; charset=utf-8'];
            if (Setting::getBool('subscribe_show_userinfo', true)) {
                $headers['Subscription-Userinfo'] = $this->userinfoHeader($user);
            }

            return response($rendered, 200, $headers);
        }

        $body = $this->subscriptionLinkService->buildSubscriptionBody($user);

        $headers = [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="subscription.txt"',
        ];

        if (Setting::getBool('subscribe_show_userinfo', true)) {
            $headers['Subscription-Userinfo'] = $this->userinfoHeader($user);
        }

        return response($body, 200, $headers);
    }

    /**
     * Same auth/enable checks as show(), scoped to only the shared-plan
     * nodes — see SubscriptionLinkService::buildSharedOnlySubscriptionBody().
     * Also mirrors show()'s per-client template rendering, so Clash-type
     * clients opening this merged link get a proper YAML config too.
     */
    public function showShared(string $token, Request $request): Response
    {
        if (! Setting::getBool('plugin.subscription_endpoint_enabled', true)) {
            abort(503, '订阅服务暂时不可用');
        }

        $user = User::where('token', $token)->first();

        if (! $user) {
            abort(404);
        }

        if ($user->banned) {
            abort(403);
        }

        $client = $this->detectClient($request);
        $rendered = $client ? $this->clientConfigRenderer->renderShared($client, $user) : null;

        if ($rendered !== null) {
            $headers = ['Content-Type' => 'text/plain; charset=utf-8'];
            if (Setting::getBool('subscribe_show_userinfo', true)) {
                $headers['Subscription-Userinfo'] = $this->userinfoHeader($user);
            }

            return response($rendered, 200, $headers);
        }

        $body = $this->subscriptionLinkService->buildSharedOnlySubscriptionBody($user);

        $headers = [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="subscription.txt"',
        ];

        if (Setting::getBool('subscribe_show_userinfo', true)) {
            $headers['Subscription-Userinfo'] = $this->userinfoHeader($user);
        }

        return response($body, 200, $headers);
    }

    /**
     * Same auth/enable checks as show(), scoped to a single dedicated-ISP
     * instance — gives that one instance its own fetchable HTTP endpoint so
     * its dashboard card can offer one-click-import deep links the same way
     * the shared-plan card does, instead of just a raw share-link string.
     */
    public function showInstance(string $token, int $instance, Request $request): Response
    {
        if (! Setting::getBool('plugin.subscription_endpoint_enabled', true)) {
            abort(503, '订阅服务暂时不可用');
        }

        $user = User::where('token', $token)->first();

        if (! $user) {
            abort(404);
        }

        if ($user->banned) {
            abort(403);
        }

        $subscriptionInstance = SubscriptionInstance::where('id', $instance)->where('user_id', $user->id)->first();

        if (! $subscriptionInstance || ! $subscriptionInstance->isAvailable()) {
            abort(404);
        }

        $client = $this->detectClient($request);
        $rendered = $client ? $this->clientConfigRenderer->renderInstance($client, $subscriptionInstance, $user) : null;

        if ($rendered !== null) {
            $headers = ['Content-Type' => 'text/plain; charset=utf-8'];
            if (Setting::getBool('subscribe_show_userinfo', true)) {
                $headers['Subscription-Userinfo'] = $this->userinfoHeader($user);
            }

            return response($rendered, 200, $headers);
        }

        $body = $this->subscriptionLinkService->buildInstanceOnlySubscriptionBody($subscriptionInstance);

        $headers = [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="subscription.txt"',
        ];

        if (Setting::getBool('subscribe_show_userinfo', true)) {
            $headers['Subscription-Userinfo'] = $this->userinfoHeader($user);
        }

        return response($body, 200, $headers);
    }

    private function userinfoHeader(User $user): string
    {
        $info = $this->subscriptionLinkService->buildUserinfo($user);
        $parts = [
            "upload={$info['upload']}",
            "download={$info['download']}",
            "total={$info['total']}",
        ];
        if ($info['expire'] !== null) {
            $parts[] = "expire={$info['expire']}";
        }

        return implode('; ', $parts);
    }
}
