<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscribeTemplate extends Model
{
    use \Stancl\Tenancy\Database\Concerns\CentralConnection; // 平台共享，分站从 central 读
    public const CLIENT_SINGBOX = 'singbox';
    public const CLIENT_CLASH = 'clash';
    public const CLIENT_CLASH_META = 'clash_meta';
    public const CLIENT_STASH = 'stash';
    public const CLIENT_SURGE = 'surge';
    public const CLIENT_SURFBOARD = 'surfboard';

    public const LABELS = [
        self::CLIENT_SINGBOX => 'Sing-box',
        self::CLIENT_CLASH => 'Clash',
        self::CLIENT_CLASH_META => 'Clash Meta',
        self::CLIENT_STASH => 'Stash',
        self::CLIENT_SURGE => 'Surge',
        self::CLIENT_SURFBOARD => 'Surfboard',
    ];

    /**
     * Clash, Clash Meta, and Stash all consume the same Clash-dialect YAML
     * `proxies:` list format — real fact about those clients, not a
     * shortcut — so they share one proxy serializer.
     */
    public const YAML_CLIENTS = [self::CLIENT_CLASH, self::CLIENT_CLASH_META, self::CLIENT_STASH];

    protected $fillable = ['client', 'content'];
}
