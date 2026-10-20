<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\SubscriptionInstance;
use App\Models\Tenant;
use App\Services\NodeRelayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Receives onip.cc's UPDATE_STATIC_INSTANCE callback (IP_CHANGE, EXPIRY,
 * RENEWAL, CREDENTIALS_CHANGE — see openapi-v2.md section 8.3) and keeps
 * the matching SubscriptionInstance's meta in sync with whatever changed
 * on the vendor's side.
 *
 * The vendor's own docs don't specify a signature for inbound callbacks
 * (unlike their outbound API auth, which is HMAC-SHA256 signed) — only "use
 * HTTPS". Since a callback can extend an instance's expiry or rewrite its
 * routed IP/credentials, we can't leave it open: we gate it on a shared
 * secret that WE bake into the callback URL registered with onip (system
 * 设置 → onip 回调地址 already carries ?secret=…). Fail-closed — no secret
 * configured, or a mismatch, and the request is rejected before it can
 * mutate anything. The conservative "only touch an instance whose proxy_id
 * already exists, never create one" behaviour stays as a second layer.
 *
 * CREATE_STATIC_ORDER callbacks aren't handled here — OnipProvisioner uses
 * sync=true so the provisioned result comes back directly in the
 * order-create response instead of needing this webhook for that part.
 */
class OnipCallbackController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->secretMatches($request)) {
            Log::warning('onip callback rejected: bad or missing secret', ['ip' => $request->ip()]);

            return response()->json(['status' => 'unauthorized'], 403);
        }

        $type = $request->input('type');

        if ($type !== 'UPDATE_STATIC_INSTANCE') {
            return response()->json(['status' => 'ignored']);
        }

        $data = $request->input('data', []);
        $proxyId = $data['proxyId'] ?? null;

        if ((! is_string($proxyId) && ! is_int($proxyId)) || (string) $proxyId === '') {
            return response()->json(['status' => 'ignored']);
        }
        $proxyId = (string) $proxyId;

        // This endpoint deliberately starts in central context, while the
        // instance may belong to the platform or any tenant. Locate it across
        // every site before mutating anything. Refuse ambiguous proxy ids so a
        // collision can never rewrite another site's credentials.
        $matches = $this->matchingSites($proxyId);
        if ($matches === []) {
            return response()->json(['status' => 'ignored']);
        }
        if (count($matches) !== 1) {
            Log::error('onip callback proxy id is not globally unique', [
                'proxy_id' => $proxyId,
                'matches' => count($matches),
            ]);

            return response()->json(['status' => 'ambiguous'], 409);
        }

        $tenantId = $matches[0];
        $updated = $tenantId === null
            ? $this->updateInstance($proxyId, $data)
            : Tenant::find($tenantId)?->run(fn () => $this->updateInstance($proxyId, $data));

        // The record can disappear between lookup and update. Treat that the
        // same as an unknown callback and do not create vendor-controlled data.
        if (! $updated) {
            return response()->json(['status' => 'ignored']);
        }

        // IP change / expiry alters what the relay must route. Run this after
        // tenant context has ended so sync() can aggregate every site.
        try {
            app(NodeRelayService::class)->sync();
        } catch (\Throwable $e) {
            Log::warning('relay sync after onip callback failed', [
                'proxy_id' => $proxyId,
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Return one entry per matching row: null for 主站, tenant id for 分站.
     * Duplicate rows inside one database remain duplicates and are rejected.
     *
     * @return list<?string>
     */
    private function matchingSites(string $proxyId): array
    {
        $matches = array_fill(
            0,
            SubscriptionInstance::whereJsonContains('meta->proxy_id', $proxyId)->count(),
            null,
        );

        foreach (Tenant::all() as $tenant) {
            try {
                $count = $tenant->run(fn () => SubscriptionInstance::whereJsonContains('meta->proxy_id', $proxyId)->count());
                for ($i = 0; $i < $count; $i++) {
                    $matches[] = (string) $tenant->getTenantKey();
                }
            } catch (\Throwable $e) {
                Log::warning('onip callback could not inspect tenant database', [
                    'tenant_id' => $tenant->getTenantKey(),
                    'proxy_id' => $proxyId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $matches;
    }

    private function updateInstance(string $proxyId, array $data): bool
    {
        $instance = SubscriptionInstance::whereJsonContains('meta->proxy_id', $proxyId)->first();
        if (! $instance) {
            return false;
        }

        $meta = $instance->meta ?? [];
        $meta['ip'] = $data['ip'] ?? $meta['ip'] ?? null;
        $meta['port'] = $data['port'] ?? $meta['port'] ?? null;
        $meta['username'] = $data['username'] ?? $meta['username'] ?? null;
        $meta['password'] = $data['password'] ?? $meta['password'] ?? null;

        $updates = ['meta' => $meta];
        if (! empty($data['expiresAt'])) {
            $updates['expired_at'] = Carbon::createFromTimestampMs($data['expiresAt']);
        }
        if (($data['status'] ?? null) === 3) { // vendor's "已过期"
            $updates['status'] = SubscriptionInstance::STATUS_DISABLED;
        }

        $instance->update($updates);

        return true;
    }

    /**
     * Constant-time check of the shared secret (query ?secret= or the
     * X-Onip-Secret header) against the configured onip_callback_secret.
     * Returns false when no secret is configured, so the endpoint is closed
     * until an admin sets one — the callback URL shown in 系统设置 already
     * includes it.
     */
    private function secretMatches(Request $request): bool
    {
        $expected = (string) Setting::get('onip_callback_secret', '');

        if ($expected === '') {
            return false;
        }

        $provided = (string) ($request->query('secret') ?? $request->header('X-Onip-Secret', ''));

        return hash_equals($expected, $provided);
    }
}
