<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\SubscriptionInstance;
use App\Models\Tenant;
use App\Models\TrafficLog;
use App\Models\User;
use Illuminate\Http\Request;

class TrafficReportController extends Controller
{
    private const TRANSACTION_ATTEMPTS = 3;

    /**
     * Called by a real node agent to report traffic usage since its last
     * report. No user auth — the shared-server heartbeat secret or the
     * dedicated instance's own token is the credential, same pattern as
     * the heartbeat endpoint. Upserts into today's row so repeated reports
     * throughout the day accumulate rather than overwrite.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'in:server,instance'],
            'secret' => ['required', 'string'],
            'uuid' => ['required_if:type,server', 'nullable', 'string'],
            'upload' => ['required', 'integer', 'min:0'],
            'download' => ['required', 'integer', 'min:0'],
        ]);

        if ($data['type'] === 'server') {
            return $this->reportServer($data);
        }

        return $this->reportInstance($data);
    }

    /** Shared-node traffic: user is found by uuid across 主站 + 分站; recorded in the owning DB. */
    private function reportServer(array $data)
    {
        $server = Server::findByHeartbeatSecret($data['secret']);
        if (! $server) {
            return response()->json(['status' => 'fail', 'message' => 'invalid secret'], 404);
        }

        $record = function () use ($server, $data): bool {
            $user = User::where('uuid', $data['uuid'])->first();
            if (! $user) {
                return false;
            }

            if ($data['upload'] === 0 && $data['download'] === 0) {
                return true;
            }

            $date = now()->toDateString();
            TrafficLog::query()->getConnection()->transaction(function () use ($server, $user, $data, $date) {
                // whereNull() (not a null in the search array) matches the
                // existing row and accumulates rather than inserting each report.
                $log = TrafficLog::where('user_id', $user->id)
                    ->where('server_id', $server->id)
                    ->whereNull('subscription_instance_id')
                    ->whereDate('date', $date)
                    ->lockForUpdate()
                    ->first() ?? new TrafficLog([
                        'user_id' => $user->id, 'server_id' => $server->id,
                        'subscription_instance_id' => null, 'date' => $date,
                    ]);
                $log->rate = (float) $server->rate;
                $log->upload_bytes = (int) ($log->upload_bytes ?? 0) + $data['upload'];
                $log->download_bytes = (int) ($log->download_bytes ?? 0) + $data['download'];
                $log->save();
            }, self::TRANSACTION_ATTEMPTS);

            return true;
        };

        return $this->onCentralThenTenants($record)
            ? response()->json(['status' => 'success'])
            : response()->json(['status' => 'fail', 'message' => 'invalid uuid'], 404);
    }

    /** ISP relay traffic: instance is found by token across 主站 + 分站; recorded in the owning DB. */
    private function reportInstance(array $data)
    {
        $record = function () use ($data): bool {
            $instance = SubscriptionInstance::where('token', $data['secret'])->first();
            if (! $instance) {
                return false;
            }

            if ($data['upload'] === 0 && $data['download'] === 0) {
                return true;
            }

            $date = now()->toDateString();

            return (bool) TrafficLog::query()->getConnection()->transaction(function () use ($instance, $data, $date) {
                $instance = SubscriptionInstance::whereKey($instance->id)
                    ->lockForUpdate()
                    ->first();
                if (! $instance) {
                    return false;
                }

                $log = TrafficLog::where('user_id', $instance->user_id)
                    ->where('subscription_instance_id', $instance->id)
                    ->whereNull('server_id')
                    ->whereDate('date', $date)
                    ->lockForUpdate()
                    ->first() ?? new TrafficLog([
                        'user_id' => $instance->user_id, 'server_id' => null,
                        'subscription_instance_id' => $instance->id, 'date' => $date,
                    ]);
                $log->rate = 1.00;
                $log->upload_bytes = (int) ($log->upload_bytes ?? 0) + $data['upload'];
                $log->download_bytes = (int) ($log->download_bytes ?? 0) + $data['download'];
                $log->save();

                $instance->u = (int) $instance->u + $data['upload'];
                $instance->d = (int) $instance->d + $data['download'];
                $instance->save();

                return true;
            }, self::TRANSACTION_ATTEMPTS);
        };

        return $this->onCentralThenTenants($record)
            ? response()->json(['status' => 'success'])
            : response()->json(['status' => 'fail', 'message' => 'invalid secret'], 404);
    }

    /**
     * Run $record() on central first; if the identity isn't there (returns
     * false), try each 分站 in its own context. Central matches first, so the
     * 主站's own reporting path is unchanged.
     */
    private function onCentralThenTenants(callable $record): bool
    {
        if ($record()) {
            return true;
        }

        foreach (Tenant::all() as $tenant) {
            $done = false;
            $tenant->run(function () use ($record, &$done) {
                $done = $record();
            });
            if ($done) {
                return true;
            }
        }

        return false;
    }
}
