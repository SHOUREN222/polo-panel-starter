<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RoutingRule;
use App\Models\Server;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\TrafficLog;
use App\Services\SharedNodeService;
use App\Support\NodeUserId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Implements V2bX's "UniProxy" protocol (github.com/wyx2685/V2bX,
 * api/panel/{node,user}.go) so an unmodified V2bX binary on a shared node
 * can be pointed at this panel instead of a real Xboard instance. Field
 * names/shapes here are taken directly from V2bX's own request structs,
 * not guessed — see NodeInfo/UserInfo/UserTraffic in that source.
 *
 * Only shadowsocks and vless+reality are implemented (the only protocols
 * this panel actually issues shared-node subscription links for). A node
 * configured for any other type gets an honest 400, not a silently wrong
 * config.
 */
class UniProxyController extends Controller
{
    private const MAX_TRAFFIC_ENTRIES = 10_000;

    private const MAX_TRAFFIC_BYTES_PER_COUNTER = 1_099_511_627_776; // 1 TiB per report.

    private const TRAFFIC_BATCH_SIZE = 200;

    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(private readonly SharedNodeService $sharedNodeService) {}

    public function config(Request $request): JsonResponse
    {
        $server = $this->authenticate($request);
        if (! $server) {
            return $this->unauthorized();
        }

        $server->update(['last_seen_at' => now()]);

        $common = [
            'host' => $server->host,
            'server_port' => (int) $server->port,
            'server_name' => '',
            'routes' => RoutingRule::nodeRoutes(),
            'base_config' => [
                'push_interval' => (int) Setting::get('node_push_interval', 60),
                'pull_interval' => (int) Setting::get('node_pull_interval', 60),
            ],
        ];

        return match ($server->protocol) {
            'shadowsocks' => response()->json([
                ...$common,
                'cipher' => $server->method ?: 'aes-256-gcm',
                'server_key' => '',
            ]),
            // Field names/values are matched to what V2bX (0.4.0 + xray core)
            // actually accepts, verified byte-for-byte against a working
            // Xboard UniProxy response. Two things V2bX is strict about:
            //   - the key must be `decryption` (null → V2bX defaults it to
            //     "none"); sending `encryption:"none"` makes V2bX build a bad
            //     inbound and xray dies with
            //     "vless decryption method none is not support".
            //   - `flow` must be xtls-rprx-vision to match the flow the client
            //     subscription uses (ClientConfigRenderer defaults to it). With
            //     flow null the server account carries no flow and xray rejects
            //     the client: "account … is not able to use the flow
            //     xtls-rprx-vision". `networkSettings` is camelCase.
            'vless' => response()->json([
                ...$common,
                'protocol' => 'vless',
                'listen_ip' => '0.0.0.0',
                'tls' => 2, // V2bX Security const: Reality
                'flow' => $server->config['flow'] ?? 'xtls-rprx-vision',
                'decryption' => null,
                'network' => 'tcp',
                'networkSettings' => null,
                'tls_settings' => [
                    'server_name' => $server->config['sni'] ?? '',
                    'server_port' => '443',
                    'public_key' => $server->config['public_key'] ?? '',
                    'private_key' => $server->config['private_key'] ?? '',
                    'short_id' => $server->config['short_id'] ?? '',
                    'allow_insecure' => false,
                ],
            ]),
            default => response()->json(['status' => 'fail', 'message' => "unsupported protocol for node sync: {$server->protocol}"], 400),
        };
    }

    public function user(Request $request): JsonResponse
    {
        $server = $this->authenticate($request);
        if (! $server) {
            return $this->unauthorized();
        }

        $mapUsers = fn (int $ordinal) => $this->sharedNodeService->getEligibleUsers($server)->map(fn ($user) => [
            'id' => NodeUserId::encode($ordinal, $user->id),
            'uuid' => $user->uuid,
            'speed_limit' => (int) ($user->plan?->speed_limit ?? 0),
            'device_limit' => 0,
        ])->values()->all();

        // 主站 (central, ordinal 0 → raw ids, unchanged) …
        $users = $mapUsers(0);

        // … plus every 分站's eligible users, keyed by their global (offset) ids.
        foreach (Tenant::all() as $tenant) {
            $ordinal = (int) ($tenant->node_ordinal ?? 0);
            if ($ordinal <= 0) {
                continue;
            }
            $tenantUsers = [];
            $tenant->run(function () use ($mapUsers, $ordinal, &$tenantUsers) {
                $tenantUsers = $mapUsers($ordinal);
            });
            $users = array_merge($users, $tenantUsers);
        }

        return response()->json(['users' => array_values($users)]);
    }

    public function push(Request $request): JsonResponse
    {
        $server = $this->authenticate($request);
        if (! $server) {
            return $this->unauthorized();
        }

        $reports = $this->validatedTrafficReports($request);
        if ($reports instanceof JsonResponse) {
            return $reports;
        }

        // ordinal → tenant, so a global id decodes back to (which 分站, local id).
        $tenantsByOrdinal = Tenant::all()
            ->filter(fn ($t) => (int) ($t->node_ordinal ?? 0) > 0)
            ->keyBy(fn ($t) => (int) $t->node_ordinal);

        // Group by physical database so a node push uses a handful of write
        // transactions instead of opening one transaction for every user.
        $reportsByOrdinal = [];
        foreach ($reports as [$ordinal, $localId, $upload, $download]) {
            if (($upload === 0 && $download === 0)
                || ($ordinal !== 0 && ! $tenantsByOrdinal->has($ordinal))) {
                continue;
            }

            $reportsByOrdinal[$ordinal][] = [$localId, $upload, $download];
        }

        // Reject the whole batch before the first write if an accumulated
        // counter would overflow PHP/database's signed 64-bit integer range.
        foreach ($reportsByOrdinal as $ordinal => $databaseReports) {
            $hasHeadroom = match (true) {
                $ordinal === 0 => $this->trafficBatchHasHeadroom($server, $databaseReports),
                default => $tenantsByOrdinal->get($ordinal)
                    ->run(fn () => $this->trafficBatchHasHeadroom($server, $databaseReports)),
            };

            if (! $hasHeadroom) {
                return $this->invalidTraffic('traffic counter would overflow');
            }
        }

        foreach ($reportsByOrdinal as $ordinal => $databaseReports) {
            if ($ordinal === 0) {
                $this->recordTrafficBatches($server, $databaseReports);
            } else {
                $tenantsByOrdinal->get($ordinal)
                    ->run(fn () => $this->recordTrafficBatches($server, $databaseReports));
            }
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Validate the complete report before writing anything. V2bX sends a JSON
     * object keyed by global user id, with [upload, download] byte counters.
     *
     * @return array<int, array{0:int,1:int,2:int,3:int}>|JsonResponse
     */
    private function validatedTrafficReports(Request $request): array|JsonResponse
    {
        if (! $request->isJson()) {
            return $this->invalidTraffic('traffic report must use application/json');
        }

        // Request::all() also contains node_id/node_type from the query string;
        // the traffic map is exclusively the JSON request body.
        $payload = $request->json()->all();

        if (count($payload) > self::MAX_TRAFFIC_ENTRIES) {
            return $this->invalidTraffic('too many traffic entries');
        }

        $reports = [];

        foreach ($payload as $globalId => $traffic) {
            $globalId = $this->positiveInteger($globalId);
            if ($globalId === null) {
                return $this->invalidTraffic('user id must be a positive integer');
            }

            if (! is_array($traffic) || ! array_is_list($traffic) || count($traffic) !== 2) {
                return $this->invalidTraffic("traffic for user {$globalId} must contain upload and download");
            }

            [$upload, $download] = $traffic;
            if (! is_int($upload) || ! is_int($download) || $upload < 0 || $download < 0) {
                return $this->invalidTraffic("traffic for user {$globalId} must use non-negative integers");
            }

            if ($upload > self::MAX_TRAFFIC_BYTES_PER_COUNTER || $download > self::MAX_TRAFFIC_BYTES_PER_COUNTER) {
                return $this->invalidTraffic("traffic for user {$globalId} exceeds the per-report limit");
            }

            [$ordinal, $localId] = NodeUserId::decode($globalId);
            if ($localId <= 0) {
                return $this->invalidTraffic("user id {$globalId} is invalid");
            }

            $reports[] = [$ordinal, $localId, $upload, $download];
        }

        return $reports;
    }

    private function positiveInteger(int|string $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }

        $max = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($max) || (strlen($value) === strlen($max) && strcmp($value, $max) > 0)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * Record reports on the current central/tenant connection. With SQLite's
     * IMMEDIATE mode, each transaction owns the writer slot before it reads.
     *
     * @param  array<int, array{0:int,1:int,2:int}>  $reports
     */
    private function recordTrafficBatches(Server $server, array $reports): void
    {
        $connection = TrafficLog::query()->getConnection();
        $date = now()->toDateString();

        foreach (array_chunk($reports, self::TRAFFIC_BATCH_SIZE) as $batch) {
            $connection->transaction(function () use ($server, $batch, $date) {
                foreach ($batch as [$userId, $upload, $download]) {
                    $log = TrafficLog::where('user_id', $userId)
                        ->where('server_id', $server->id)
                        ->whereNull('subscription_instance_id')
                        ->whereDate('date', $date)
                        ->lockForUpdate()
                        ->first() ?? new TrafficLog([
                            'user_id' => $userId,
                            'server_id' => $server->id,
                            'subscription_instance_id' => null,
                            'date' => $date,
                        ]);

                    $currentUpload = (int) ($log->upload_bytes ?? 0);
                    $currentDownload = (int) ($log->download_bytes ?? 0);

                    if ($upload > PHP_INT_MAX - $currentUpload || $download > PHP_INT_MAX - $currentDownload) {
                        throw new \OverflowException('traffic counter overflow');
                    }

                    $log->rate = (float) $server->rate;
                    $log->upload_bytes = $currentUpload + $upload;
                    $log->download_bytes = $currentDownload + $download;
                    $log->save();
                }
            }, self::TRANSACTION_ATTEMPTS);
        }
    }

    /** @param array<int, array{0:int,1:int,2:int}> $reports */
    private function trafficBatchHasHeadroom(Server $server, array $reports): bool
    {
        $date = now()->toDateString();

        foreach (array_chunk($reports, self::TRAFFIC_BATCH_SIZE) as $batch) {
            $logs = TrafficLog::whereIn('user_id', array_column($batch, 0))
                ->where('server_id', $server->id)
                ->whereNull('subscription_instance_id')
                ->whereDate('date', $date)
                ->get()
                ->keyBy('user_id');

            foreach ($batch as [$userId, $upload, $download]) {
                $log = $logs->get($userId);
                $currentUpload = (int) ($log?->upload_bytes ?? 0);
                $currentDownload = (int) ($log?->download_bytes ?? 0);

                if ($upload > PHP_INT_MAX - $currentUpload || $download > PHP_INT_MAX - $currentDownload) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Device-limit enforcement (V2bX's online-IP tracking) isn't a feature
     * this panel has anywhere else, so this honestly reports "no limit
     * data" rather than faking enforcement — always an empty alive map.
     */
    public function aliveList(Request $request): JsonResponse
    {
        $server = $this->authenticate($request);
        if (! $server) {
            return $this->unauthorized();
        }

        return response()->json(['alive' => new \stdClass]);
    }

    /**
     * V2bX posts its online-IP-per-user map here; accepted and discarded
     * since there's no device-limit feature to feed it into yet.
     */
    public function alive(Request $request): JsonResponse
    {
        $server = $this->authenticate($request);
        if (! $server) {
            return $this->unauthorized();
        }

        return response()->json(['status' => 'success']);
    }

    private function authenticate(Request $request): ?Server
    {
        $nodeId = $request->query('node_id');
        $token = $request->bearerToken();

        if (! $token && config('security.allow_legacy_node_query_token', false)) {
            $token = $request->query('token');
        }

        $nodeId = $this->positiveInteger(is_int($nodeId) || is_string($nodeId) ? $nodeId : '');
        if ($nodeId === null || ! is_string($token) || $token === '') {
            return null;
        }

        $server = Server::findByHeartbeatSecret($token);
        if (! $server || $server->getKey() !== $nodeId) {
            return null;
        }

        return $server;
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json(['status' => 'fail', 'message' => 'invalid node_id or token'], 403);
    }

    private function invalidTraffic(string $message): JsonResponse
    {
        return response()->json(['status' => 'fail', 'message' => $message], 422);
    }
}
