<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * One static-ISP relay node the panel pushes the shared xray-relay config to.
 * There can be any number; a customer's subscription lists every deliverable
 * one (ordered by priority) as a failover path to the SAME onip IP. Reality
 * identity (keys / sni) is NOT here — it is shared platform-wide in Settings
 * (relay_reality_*), because every relay must present the same one for uuid
 * failover to work. Relay infrastructure is platform-level → CentralConnection.
 */
class RelayNode extends Model
{
    use CentralConnection;

    protected $fillable = [
        'label', 'priority', 'enabled',
        'node_host', 'node_port',
        'ssh_host', 'ssh_port', 'ssh_user', 'ssh_password', 'ssh_key_path',
        'config_path', 'reload_cmd',
    ];

    protected $casts = [
        'priority' => 'integer',
        'enabled' => 'boolean',
        'node_port' => 'integer',
        'ssh_port' => 'integer',
        'ssh_password' => 'encrypted',
    ];

    protected $hidden = ['ssh_password'];
}
