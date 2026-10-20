<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoutingRule extends Model
{
    // 路由规则随节点配置下发给共享节点，是平台级的；租户库里没有这张表。
    use \Stancl\Tenancy\Database\Concerns\CentralConnection;

    public const ACTION_DIRECT = 'direct';
    public const ACTION_PROXY = 'proxy';
    public const ACTION_BLOCK = 'block';

    public const SCOPE_SHARED = 'shared';
    public const SCOPE_ISP = 'isp';
    public const SCOPE_BOTH = 'both';

    protected $fillable = ['name', 'pattern', 'action', 'scope', 'enabled', 'sort'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    /** True when the pattern is an IP / CIDR (vs a domain). */
    public function isIpRule(): bool
    {
        return (bool) preg_match('#^\d{1,3}(\.\d{1,3}){3}(/\d{1,2})?$#', trim($this->pattern));
    }

    /**
     * The pattern as an xray/V2bX routing match token: a CIDR/IP as-is, or a
     * domain as `domain:example.com` (matches the domain and its subdomains;
     * a leading `*.` wildcard is stripped).
     */
    public function matchToken(): string
    {
        $p = trim($this->pattern);

        return $this->isIpRule() ? $p : 'domain:'.ltrim($p, '*.');
    }

    /**
     * Enabled rules whose scope covers the given node type ('shared' | 'isp')
     * — i.e. rules scoped to that type or to 'both'. Ordered by sort.
     *
     * @return \Illuminate\Support\Collection<int,self>
     */
    public static function activeFor(string $nodeType): \Illuminate\Support\Collection
    {
        return static::where('enabled', true)
            ->whereIn('scope', [$nodeType, self::SCOPE_BOTH])
            ->orderBy('sort')->orderBy('id')->get();
    }

    /**
     * V2board/V2bX `routes` config shape — what the panel hands shared nodes
     * so they build xray routing rules. Only rules scoped to shared/both.
     *
     * @return list<array{id:int,match:list<string>,action:string}>
     */
    public static function nodeRoutes(): array
    {
        return static::activeFor(self::SCOPE_SHARED)
            ->map(fn (self $r) => ['id' => $r->id, 'match' => [$r->matchToken()], 'action' => $r->action])
            ->values()
            ->all();
    }
}
