<?php

namespace App\Support;

/**
 * Globally-unique user id for the node (V2bX/UniProxy) protocol across tenants.
 *
 * Nodes are shared infrastructure serving customers from the central 主站 AND
 * every 分站, but each tenant DB has its own auto-increment user ids (central
 * #5 and 分站A #5 collide). The node keys traffic by a single integer id, so we
 * encode a per-tenant ordinal into it:
 *
 *   global_id = ordinal * OFFSET + local_id
 *
 * The **central 主站 uses ordinal 0**, so its global_id == local_id — the node
 * protocol for existing 主站 customers is byte-identical to before (zero risk).
 * Each 分站 gets a stable positive ordinal (assigned at tenant creation).
 */
class NodeUserId
{
    // 1e9 local ids per tenant; ordinal up to ~9.2e9 within int64. Central=0.
    public const OFFSET = 1_000_000_000;

    public static function encode(int $ordinal, int $localId): int
    {
        return $ordinal * self::OFFSET + $localId;
    }

    /** @return array{0:int,1:int} [ordinal, localId] */
    public static function decode(int $globalId): array
    {
        return [intdiv($globalId, self::OFFSET), $globalId % self::OFFSET];
    }
}
