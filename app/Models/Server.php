<?php

namespace App\Models;

use App\Casts\EncryptedConfigArray;
use App\Support\EncryptedValue;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class Server extends Model
{
    // 节点是平台共享基础设施：分站上下文里也从 central 主库读。
    use CentralConnection;

    protected $fillable = ['name', 'group_id', 'host', 'port', 'protocol', 'method', 'config', 'rate', 'show', 'last_seen_at'];

    protected $hidden = ['heartbeat_secret', 'heartbeat_secret_hash'];

    protected $casts = [
        'show' => 'boolean',
        'rate' => 'decimal:2',
        'last_seen_at' => 'datetime',
        'config' => EncryptedConfigArray::class,
    ];

    protected static function booted(): void
    {
        static::creating(function (Server $server) {
            $server->heartbeat_secret ??= (string) Str::uuid();
            $server->syncHeartbeatSecretHash();
        });

        static::updating(function (Server $server) {
            if ($server->isDirty('heartbeat_secret')) {
                $server->syncHeartbeatSecretHash();
            }
        });
    }

    protected function heartbeatSecret(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => EncryptedValue::decrypt($value),
            set: fn (?string $value) => EncryptedValue::encrypt($value),
        );
    }

    public static function findByHeartbeatSecret(string $secret): ?self
    {
        $server = static::where('heartbeat_secret_hash', hash('sha256', $secret))->first();

        if (! $server || ! hash_equals((string) $server->heartbeat_secret, $secret)) {
            return null;
        }

        return $server;
    }

    /**
     * Issue a fresh node token, invalidating the old one immediately.
     *
     * The token is what a node authenticates with to pull the user list and
     * report traffic, so a leaked one lets anyone read every customer's UUID.
     * After rotating, the node itself must be reconfigured with the new value
     * and restarted — until then it can't talk to the panel (existing customer
     * connections keep working from its in-memory user list; only syncing and
     * traffic reporting pause).
     */
    public function rotateHeartbeatSecret(): string
    {
        $this->heartbeat_secret = (string) Str::uuid();
        $this->save();

        return $this->heartbeat_secret;
    }

    private function syncHeartbeatSecretHash(): void
    {
        $schema = Schema::connection($this->getConnectionName());

        // The model is used by the older migration that first adds the secret;
        // the hash column does not exist at that point during a fresh install.
        if ($schema->hasColumn($this->getTable(), 'heartbeat_secret_hash')) {
            $secret = $this->heartbeat_secret;
            $this->heartbeat_secret_hash = $secret === null ? null : hash('sha256', $secret);
        }
    }

    public function scopeVisible($query)
    {
        return $query->where('show', true);
    }

    public function scopeForGroup($query, int $groupId)
    {
        return $query->where('group_id', $groupId);
    }

    /**
     * A node is considered online if it reported a heartbeat within the
     * last 5 minutes. There's no real agent yet — see 节点监控 in the admin
     * panel for how a node would report in via POST /api/servers/heartbeat.
     */
    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gt(now()->subMinutes(5));
    }
}
