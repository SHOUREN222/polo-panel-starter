<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\Setting;
use Illuminate\Http\Request;

class NodeConfigController extends Controller
{
    /**
     * A real node agent calls this at startup (and on its own pull-interval
     * schedule) to fetch its own connection config instead of that being
     * hand-copied into a local node config file. Auth is a single global
     * key (系统设置 → 节点配置 → 通讯密钥), not the per-server heartbeat
     * secret — that secret is returned *in* the response so the node can
     * then use it for its heartbeat/traffic-report calls.
     *
     * The key is read from `X-Node-Key`, so it never lands in a URL or access
     * log. A query-string fallback exists only behind the short-lived node
     * migration switch.
     */
    public function show(Request $request)
    {
        $key = (string) Setting::get('node_comm_key', '');
        $provided = $request->header('X-Node-Key');
        if ($provided === null && config('security.allow_legacy_node_query_token', false)) {
            $provided = $request->query('key');
        }
        $provided = is_string($provided) ? $provided : '';

        if ($key === '' || ! hash_equals($key, $provided)) {
            return response()->json(['status' => 'fail', 'message' => 'invalid key'], 403);
        }

        $pullInterval = (int) Setting::get('node_pull_interval', 60);
        $pushInterval = (int) Setting::get('node_push_interval', 60);

        $serverId = $request->query('server_id');
        $servers = $serverId
            ? Server::where('id', $serverId)->get()
            : Server::visible()->get();

        return response()->json([
            'pull_interval' => $pullInterval,
            'push_interval' => $pushInterval,
            'servers' => $servers->map(fn (Server $server) => [
                'id' => $server->id,
                'name' => $server->name,
                'host' => $server->host,
                'port' => $server->port,
                'protocol' => $server->protocol,
                'method' => $server->method,
                'rate' => (float) $server->rate,
                'heartbeat_secret' => $server->heartbeat_secret,
            ]),
        ]);
    }
}
