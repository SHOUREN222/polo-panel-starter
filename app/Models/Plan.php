<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class Plan extends Model
{
    // 套餐目录是平台共享数据：在分站上下文里也从 central 主库读，而非分站库。
    use CentralConnection;

    public const TYPE_SHARED = 'shared';

    public const TYPE_DEDICATED_ISP = 'dedicated_isp';

    public const RESET_MODE_ANNIVERSARY = 'anniversary';

    public const RESET_MODE_NEVER = 'never';

    /**
     * Months billed per subscription period, used by auto-pricing to scale the
     * monthly price (a year = 12× a month). Distinct from the actual provisioned
     * duration (a "year" runs 365 days — see OnipProvisioner::DAYS_PER_PERIOD);
     * the ~5-day gap is comfortably covered by the fixed monthly profit.
     */
    public const PERIOD_MONTHS = [
        'month' => 1,
        'quarter' => 3,
        'half_year' => 6,
        'year' => 12,
        'two_year' => 24,
        'three_year' => 36,
    ];

    protected $attributes = [
        'sell' => true,
        'show' => true,
        'allow_renewal' => true,
    ];

    protected $fillable = [
        'name', 'tags', 'type', 'group_id', 'transfer_enable', 'speed_limit',
        'device_limit', 'capacity_limit', 'traffic_reset_mode', 'prices',
        'country_prices',
        'resource_profile', 'description', 'sell', 'show', 'allow_renewal',
        'traffic_pack_price_per_gb', 'traffic_reset_pack_price_per_gb', 'sort',
    ];

    protected $casts = [
        'tags' => 'array',
        'prices' => 'array',
        'country_prices' => 'array',
        'resource_profile' => 'array',
        'sell' => 'boolean',
        'show' => 'boolean',
        'allow_renewal' => 'boolean',
    ];

    public function isDedicatedIsp(): bool
    {
        return $this->type === self::TYPE_DEDICATED_ISP;
    }

    /**
     * Dynamic-region onip plans don't bake a lineId into resource_profile —
     * the customer searches and picks a real line at checkout instead (see
     * OrderService::createOrder()'s $selectedLine param).
     */
    public function isDynamicIsp(): bool
    {
        return $this->isDedicatedIsp() && ($this->resource_profile['provider'] ?? null) === 'onip_dynamic';
    }

    public function priceFor(string $period): ?int
    {
        return $this->prices[$period] ?? null;
    }

    /**
     * Effective price (cents) for a period given the country the customer
     * picked: a per-country override if one is configured, otherwise the
     * plan's base `prices`. Country-blind callers (shared plans) just get the
     * base. Returns null only when the base itself has no such period.
     */
    public function priceForCountry(string $period, ?string $country): ?int
    {
        if ($country !== null && ! empty($this->country_prices[$country][$period])) {
            return (int) $this->country_prices[$country][$period];
        }

        return $this->priceFor($period);
    }

    /**
     * The cheapest configured price for a period across the base + all
     * country overrides — what the "¥xx 起" label should show. Cents.
     */
    public function cheapestPrice(string $period): ?int
    {
        $prices = [$this->priceFor($period)];
        foreach ($this->country_prices ?? [] as $byPeriod) {
            if (! empty($byPeriod[$period])) {
                $prices[] = (int) $byPeriod[$period];
            }
        }
        $prices = array_filter($prices, fn ($p) => $p !== null);

        return $prices === [] ? null : min($prices);
    }

    public function cheapestMonthPrice(): ?int
    {
        return $this->cheapestPrice('month');
    }

    /**
     * Auto-pricing config for a dynamic ISP plan, stored inside
     * resource_profile so it needs no schema change:
     *   ['enabled' => bool, 'relay' => 15, 'profit' => 20, 'round' => 5, 'monthly_adjustment' => 0].
     * Absent/disabled by default — nothing auto-prices unless turned on.
     */
    public function autoPricing(): array
    {
        $cfg = $this->resource_profile['auto_pricing'] ?? [];

        return [
            'enabled' => (bool) ($cfg['enabled'] ?? false),
            'relay' => (int) ($cfg['relay'] ?? 15),
            'profit' => (int) ($cfg['profit'] ?? 20),
            'round' => (int) ($cfg['round'] ?? 5),
            'monthly_adjustment' => (int) ($cfg['monthly_adjustment'] ?? ($cfg['adjustment'] ?? 0)),
            // Percent off for longer periods (month has none) — cheaper per
            // month the longer you commit, to encourage long-term purchases.
            'discounts' => [
                'quarter' => (int) ($cfg['discounts']['quarter'] ?? 5),
                'half_year' => (int) ($cfg['discounts']['half_year'] ?? 8),
                'year' => (int) ($cfg['discounts']['year'] ?? 12),
            ],
        ];
    }

    /**
     * Compute a selling price (cents) from an onip line's monthly cost (yuan):
     * add a fixed monthly relay cost + profit, scale by the number of months in
     * the period, apply the period's discount, then round to the nearest
     * ¥`$roundYuan` (so it ends 0/5). A monthly adjustment can be applied per
     * plan for product-tier pricing, e.g. economy lines at ¥5/month less.
     */
    public static function autoPrice(float $monthlyCostYuan, string $period, int $relayYuan = 15, int $profitYuan = 20, int $roundYuan = 5, int $discountPct = 0, int $monthlyAdjustmentYuan = 0): int
    {
        $months = self::PERIOD_MONTHS[$period] ?? 1;
        $monthlyYuan = max(0, $monthlyCostYuan + $relayYuan + $profitYuan + $monthlyAdjustmentYuan);
        $totalYuan = $monthlyYuan * $months;
        $totalYuan *= (100 - $discountPct) / 100;
        $step = max(1, $roundYuan);
        $roundedYuan = round($totalYuan / $step) * $step;

        return (int) round($roundedYuan * 100);
    }

    /**
     * The authoritative selling price (cents) for a dynamic-ISP line the
     * customer picked, or null when the line is NOT for sale. Resolution order:
     *   1. A flat plan (no per-country pricing, no auto-pricing) → base price.
     *   2. A manual per-country override for this country+period → that price.
     *   3. Auto-pricing on → onip cost + fixed relay + profit (see autoPrice).
     *   4. Otherwise → null (unpriced country/period is not for sale).
     * $lineSnapshot carries the onip 'countryCode' and monthly 'price' (yuan).
     */
    public function sellPrice(string $period, ?array $lineSnapshot): ?int
    {
        $country = $lineSnapshot['countryCode'] ?? null;
        $hasCountryPricing = ! empty($this->country_prices);
        $auto = $this->autoPricing();

        // 1. Flat plan: unrestricted, everything at the base price.
        if (! $hasCountryPricing && ! $auto['enabled']) {
            return $this->priceFor($period);
        }

        // 2. A manual per-country price always wins when present.
        if ($country !== null && ! empty($this->country_prices[$country][$period])) {
            return (int) $this->country_prices[$country][$period];
        }

        // 3. Auto-price un-configured countries from the line's real cost.
        if ($auto['enabled']) {
            $monthlyCostYuan = $lineSnapshot['price'] ?? null;
            if (is_numeric($monthlyCostYuan) && $monthlyCostYuan > 0) {
                $discount = $auto['discounts'][$period] ?? 0;

                return self::autoPrice((float) $monthlyCostYuan, $period, $auto['relay'], $auto['profit'], $auto['round'], $discount, $auto['monthly_adjustment']);
            }

            return null; // auto-pricing on but no cost to price from
        }

        // 4. Per-country pricing set, country not listed, no auto → not for sale.
        return null;
    }
}
