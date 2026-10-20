<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\Request;

class ServerHeartbeatController extends Controller
{
    /**
     * Called by a real node agent (cron/systemd timer on the node itself)
     * to report that it's alive. No user auth — the per-server secret is
     * the credential, same pattern as a webhook.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'secret' => ['required', 'string'],
        ]);

        $server = Server::findByHeartbeatSecret($data['secret']);

        if (! $server) {
            return response()->json(['status' => 'fail', 'message' => 'invalid secret'], 404);
        }

        $server->update(['last_seen_at' => now()]);

        return response()->json(['status' => 'success']);
    }
}
