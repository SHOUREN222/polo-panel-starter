<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SharedNodeService;
use Illuminate\Http\Request;

class ServerController extends Controller
{
    public function __construct(private readonly SharedNodeService $sharedNodeService)
    {
    }

    public function index(Request $request)
    {
        $servers = $this->sharedNodeService->getAvailableServers($request->user());

        return response()->json([
            'status' => 'success',
            'data' => $servers->map(fn ($server) => [
                'id' => $server->id,
                'name' => $server->name,
                'host' => $server->host,
                'port' => $server->port,
                'rate' => (float) $server->rate,
            ]),
        ]);
    }
}
