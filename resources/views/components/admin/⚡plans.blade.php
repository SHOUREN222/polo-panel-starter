<?php

use App\Models\Order;
use App\Models\Plan;
use App\Models\ServerGroup;
use App\Models\SubscriptionInstance;
use App\Services\OnipProvisioningService;
use Illuminate\Database\QueryException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin', ['title' => '套餐管理'])] class extends Component
{
    use WithPagination;

    private const EMPTY_PRICES = [
        'month' => '', 'quarter' => '', 'half_year' => '', 'year' => '', 'two_year' => '', 'three_year' => '',
    ];

    private const ONIP_SEARCH_PAGE_SIZE = 20;

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public array $tags = [];

    public string $tagInput = '';

    public string $type = 'shared';

    public ?int $group_id = 1;

    public string $transfer_enable_gb = '';

    public string $speed_limit = '';

    public ?int $device_limit = null;

    public string $capacity_limit = '';

    public string $traffic_reset_mode = 'anniversary';

    public array $prices = self::EMPTY_PRICES;

    // Per-country price overrides for dynamic-region ISP plans. Each row:
    // ['country' => 'GBR', 'month' => '75', 'quarter' => '', ...] in yuan.
    public array $countryPrices = [];

    public function addCountryPrice(): void
    {
        $this->countryPrices[] = ['country' => '', 'month' => '', 'quarter' => '', 'half_year' => '', 'year' => ''];
    }

    public function removeCountryPrice(int $i): void
    {
        unset($this->countryPrices[$i]);
        $this->countryPrices = array_values($this->countryPrices);
    }

    public string $description = '';

    public bool $showDescriptionPreview = false;

    public string $traffic_pack_price = '';

    public string $traffic_reset_pack_price = '';

    public string $resource_provider = '';

    public string $resource_country = '';

    public string $resource_city = '';

    public bool $show = true;

    public bool $sell = true;

    public bool $allow_renewal = true;

    public bool $forceUpdateExisting = false;

    public int $sort = 0;

    // onip.cc real line picker — a plan backed by a real SKU carries
    // resource_line_id + a snapshot of that SKU's display info (city/business
    // type/price) so re-opening the edit form doesn't need another API call
    // just to show what's already configured.
    public string $resource_line_id = '';

    public array $resource_line_snapshot = [];

    // Dynamic-region plans don't bake a line in at all — the customer
    // searches and picks a real line themselves at checkout (see the
    // customer-facing plans page). Only the price tier is fixed here.
    public bool $dynamicMode = false;

    public string $dynamicIspType = '2';

    // Auto-pricing: when on, any in-stock country the customer picks that has
    // no manual per-country price gets priced from its own onip cost —
    // cost + relay + profit (yuan/month), rounded to the nearest ¥round (0/5).
    public bool $autoPricingEnabled = false;

    public string $autoPricingRelay = '15';

    public string $autoPricingProfit = '20';

    public string $autoPricingRound = '5';

    public string $autoPricingMonthlyAdjustment = '0';

    // Longer-period discounts (percent off), month has none.
    public string $autoPricingDiscQuarter = '5';

    public string $autoPricingDiscHalf = '8';

    public string $autoPricingDiscYear = '12';

    public string $lineSearchCountry = '';

    public string $lineSearchCity = '';

    public string $lineSearchBusinessType = '';

    public string $lineSearchIspType = '2';

    public array $lineSearchResults = [];

    public string $lineSearchError = '';

    public bool $showAddGroupModal = false;

    public string $newGroupName = '';

    // Named successMessage (not "message") so it doesn't collide with
    // Blade's own $message variable inside @error(...)...@enderror blocks.
    public string $successMessage = '';

    public string $error = '';

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $planId): void
    {
        $plan = Plan::findOrFail($planId);

        $this->editingId = $plan->id;
        $this->name = $plan->name;
        $this->tags = $plan->tags ?? [];
        $this->type = $plan->type;
        $this->group_id = $plan->group_id;
        $this->transfer_enable_gb = (string) round($plan->transfer_enable / 1073741824, 2);
        $this->speed_limit = (string) ($plan->speed_limit ?? '');
        $this->device_limit = $plan->device_limit;
        $this->capacity_limit = (string) ($plan->capacity_limit ?? '');
        $this->traffic_reset_mode = $plan->traffic_reset_mode ?? 'anniversary';
        $this->prices = array_merge(
            self::EMPTY_PRICES,
            array_map(fn ($cents) => number_format($cents / 100, 2, '.', ''), $plan->prices ?? [])
        );
        $this->countryPrices = [];
        foreach ($plan->country_prices ?? [] as $country => $byPeriod) {
            $row = ['country' => $country];
            foreach (['month', 'quarter', 'half_year', 'year'] as $p) {
                $row[$p] = ! empty($byPeriod[$p]) ? number_format($byPeriod[$p] / 100, 2, '.', '') : '';
            }
            $this->countryPrices[] = $row;
        }
        $this->description = $plan->description ?? '';
        $this->traffic_pack_price = $plan->traffic_pack_price_per_gb ? number_format($plan->traffic_pack_price_per_gb / 100, 2, '.', '') : '';
        $this->traffic_reset_pack_price = $plan->traffic_reset_pack_price_per_gb ? number_format($plan->traffic_reset_pack_price_per_gb / 100, 2, '.', '') : '';
        $this->resource_provider = $plan->resource_profile['provider'] ?? '';
        $this->resource_country = $plan->resource_profile['country'] ?? '';
        $this->resource_city = $plan->resource_profile['city'] ?? '';
        $this->resource_line_id = (string) ($plan->resource_profile['lineId'] ?? '');
        $this->resource_line_snapshot = $plan->resource_profile['line_snapshot'] ?? [];
        $this->dynamicMode = ($plan->resource_profile['provider'] ?? null) === 'onip_dynamic';
        $this->dynamicIspType = (string) ($plan->resource_profile['ispType'] ?? '2');
        $auto = $plan->resource_profile['auto_pricing'] ?? [];
        $this->autoPricingEnabled = (bool) ($auto['enabled'] ?? false);
        $this->autoPricingRelay = (string) ($auto['relay'] ?? 15);
        $this->autoPricingProfit = (string) ($auto['profit'] ?? 20);
        $this->autoPricingRound = (string) ($auto['round'] ?? 5);
        $this->autoPricingMonthlyAdjustment = (string) ($auto['monthly_adjustment'] ?? ($auto['adjustment'] ?? 0));
        $this->autoPricingDiscQuarter = (string) ($auto['discounts']['quarter'] ?? 5);
        $this->autoPricingDiscHalf = (string) ($auto['discounts']['half_year'] ?? 8);
        $this->autoPricingDiscYear = (string) ($auto['discounts']['year'] ?? 12);
        $this->show = $plan->show;
        $this->sell = $plan->sell;
        $this->allow_renewal = $plan->allow_renewal;
        $this->forceUpdateExisting = false;
        $this->sort = $plan->sort ?? 0;

        $this->showForm = true;
    }

    public function addTag(): void
    {
        $tag = trim($this->tagInput);
        if ($tag !== '' && ! in_array($tag, $this->tags, true)) {
            $this->tags[] = $tag;
        }
        $this->tagInput = '';
    }

    public function removeTag(int $index): void
    {
        unset($this->tags[$index]);
        $this->tags = array_values($this->tags);
    }

    public function createGroup(): void
    {
        $name = trim($this->newGroupName);
        if ($name === '') {
            return;
        }

        $group = ServerGroup::create(['name' => $name]);
        $this->group_id = $group->id;
        $this->newGroupName = '';
        $this->showAddGroupModal = false;
    }

    public function save(): void
    {
        $this->error = '';

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:shared,dedicated_isp'],
            'transfer_enable_gb' => ['required', 'numeric', 'min:0.1'],
        ]);

        $prices = [];
        foreach ($this->prices as $period => $value) {
            if ($value !== '' && $value !== null) {
                $prices[$period] = (int) round(((float) $value) * 100);
            }
        }

        if (empty($prices)) {
            $this->error = '至少填一个周期的价格';

            return;
        }

        // Per-country overrides (dynamic ISP): keep only rows with a country
        // code and at least one period price, all converted yuan→cents.
        $countryPrices = [];
        foreach ($this->countryPrices as $row) {
            $country = strtoupper(trim($row['country'] ?? ''));
            if ($country === '') {
                continue;
            }
            $byPeriod = [];
            foreach (['month', 'quarter', 'half_year', 'year'] as $p) {
                if (($row[$p] ?? '') !== '') {
                    $byPeriod[$p] = (int) round(((float) $row[$p]) * 100);
                }
            }
            if ($byPeriod !== []) {
                $countryPrices[$country] = $byPeriod;
            }
        }

        // Dynamic-region profile, optionally carrying an auto-pricing policy.
        $dynamicProfile = [
            'provider' => 'onip_dynamic',
            'ispType' => (int) $this->dynamicIspType,
        ];
        if ($this->autoPricingEnabled) {
            $dynamicProfile['auto_pricing'] = [
                'enabled' => true,
                'relay' => (int) ($this->autoPricingRelay !== '' ? $this->autoPricingRelay : 15),
                'profit' => (int) ($this->autoPricingProfit !== '' ? $this->autoPricingProfit : 20),
                'round' => (int) ($this->autoPricingRound !== '' ? $this->autoPricingRound : 5),
                'monthly_adjustment' => (int) ($this->autoPricingMonthlyAdjustment !== '' ? $this->autoPricingMonthlyAdjustment : 0),
                'discounts' => [
                    'quarter' => (int) ($this->autoPricingDiscQuarter !== '' ? $this->autoPricingDiscQuarter : 0),
                    'half_year' => (int) ($this->autoPricingDiscHalf !== '' ? $this->autoPricingDiscHalf : 0),
                    'year' => (int) ($this->autoPricingDiscYear !== '' ? $this->autoPricingDiscYear : 0),
                ],
            ];
        }

        $data = [
            'name' => $this->name,
            'tags' => $this->tags,
            'type' => $this->type,
            'group_id' => $this->type === 'shared' ? $this->group_id : null,
            'transfer_enable' => (int) round(((float) $this->transfer_enable_gb) * 1073741824),
            'speed_limit' => $this->speed_limit !== '' ? (int) $this->speed_limit : null,
            'device_limit' => $this->device_limit ?: null,
            'capacity_limit' => $this->capacity_limit !== '' ? (int) $this->capacity_limit : null,
            'traffic_reset_mode' => $this->traffic_reset_mode,
            'prices' => $prices,
            'country_prices' => ($this->type === 'dedicated_isp' && $countryPrices !== []) ? $countryPrices : null,
            'description' => $this->description ?: null,
            'traffic_pack_price_per_gb' => $this->traffic_pack_price !== '' ? (int) round(((float) $this->traffic_pack_price) * 100) : null,
            'traffic_reset_pack_price_per_gb' => $this->traffic_reset_pack_price !== '' ? (int) round(((float) $this->traffic_reset_pack_price) * 100) : null,
            'resource_profile' => $this->type === 'dedicated_isp' ? ($this->dynamicMode
                ? $dynamicProfile
                : ($this->resource_line_id !== ''
                    ? [
                        'provider' => 'onip',
                        'lineId' => $this->resource_line_id,
                        'line_snapshot' => $this->resource_line_snapshot,
                    ]
                    : [
                        'provider' => $this->resource_provider ?: 'stub',
                        'country' => $this->resource_country ?: null,
                        'city' => $this->resource_city ?: null,
                    ])) : null,
            'show' => $this->show,
            'sell' => $this->sell,
            'allow_renewal' => $this->allow_renewal,
            'sort' => $this->sort,
        ];

        if ($this->editingId) {
            $plan = Plan::findOrFail($this->editingId);
            $plan->update($data);

            if ($this->forceUpdateExisting) {
                SubscriptionInstance::where('plan_id', $plan->id)
                    ->whereIn('status', [SubscriptionInstance::STATUS_PENDING, SubscriptionInstance::STATUS_ACTIVE])
                    ->update(['transfer_enable' => $plan->transfer_enable]);
            }

            $this->successMessage = '套餐已更新';
        } else {
            Plan::create($data);
            $this->successMessage = '套餐已创建';
        }

        $this->showForm = false;
        $this->resetForm();
    }

    public function toggleShow(int $planId): void
    {
        $plan = Plan::findOrFail($planId);
        $plan->update(['show' => ! $plan->show]);
    }

    public function toggleSell(int $planId): void
    {
        $plan = Plan::findOrFail($planId);
        $plan->update(['sell' => ! $plan->sell]);
    }

    public function toggleRenewal(int $planId): void
    {
        $plan = Plan::findOrFail($planId);
        $plan->update(['allow_renewal' => ! $plan->allow_renewal]);
    }

    public function delete(int $planId): void
    {
        $this->successMessage = '';
        $this->error = '';

        try {
            Plan::findOrFail($planId)->delete();
            $this->successMessage = '套餐已删除';
        } catch (QueryException $e) {
            $this->error = '该套餐已有关联订单，无法删除，可以先下架（不可购买）';
        }
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->tags = [];
        $this->tagInput = '';
        $this->type = 'shared';
        $this->group_id = 1;
        $this->transfer_enable_gb = '';
        $this->speed_limit = '';
        $this->device_limit = null;
        $this->capacity_limit = '';
        $this->traffic_reset_mode = 'anniversary';
        $this->prices = self::EMPTY_PRICES;
        $this->countryPrices = [];
        $this->description = '';
        $this->showDescriptionPreview = false;
        $this->traffic_pack_price = '';
        $this->traffic_reset_pack_price = '';
        $this->resource_provider = '';
        $this->resource_country = '';
        $this->resource_city = '';
        $this->resource_line_id = '';
        $this->resource_line_snapshot = [];
        $this->dynamicMode = false;
        $this->dynamicIspType = '2';
        $this->autoPricingEnabled = false;
        $this->autoPricingRelay = '15';
        $this->autoPricingProfit = '20';
        $this->autoPricingRound = '5';
        $this->autoPricingMonthlyAdjustment = '0';
        $this->autoPricingDiscQuarter = '5';
        $this->autoPricingDiscHalf = '8';
        $this->autoPricingDiscYear = '12';
        $this->lineSearchResults = [];
        $this->lineSearchError = '';
        $this->show = true;
        $this->sell = true;
        $this->allow_renewal = true;
        $this->forceUpdateExisting = false;
        $this->sort = 0;
    }

    /**
     * Real, live search against onip.cc's own catalog — different
     * region/business-type combinations are genuinely different SKUs
     * (each its own lineId/price/stock), so this can't be a static
     * dropdown; it has to hit the real API each time.
     */
    public function searchOnipLines(): void
    {
        $this->lineSearchError = '';
        $this->lineSearchResults = [];

        $service = app(OnipProvisioningService::class);
        if (! $service->isConfigured()) {
            $this->lineSearchError = '还没配置 onip.cc 密钥，先去"系统配置 → 静态IP开通渠道"填好 App ID/Secret';

            return;
        }

        try {
            $result = $service->searchLines(array_filter([
                'countryCode' => $this->lineSearchCountry ?: null,
                'cityCode' => $this->lineSearchCity ?: null,
                'businessType' => $this->lineSearchBusinessType ?: null,
                'ispType' => $this->lineSearchIspType !== '' ? (int) $this->lineSearchIspType : null,
                'current' => 0,
                'size' => self::ONIP_SEARCH_PAGE_SIZE,
            ]));
            $this->lineSearchResults = $result['records'] ?? [];
            if (empty($this->lineSearchResults)) {
                $this->lineSearchError = '没有搜到匹配的线路，换个条件再试';
            }
        } catch (Throwable $e) {
            $this->lineSearchError = '搜索失败：'.$e->getMessage();
        }
    }

    public function selectOnipLine(string $lineId): void
    {
        $line = collect($this->lineSearchResults)->first(fn ($l) => (string) $l['id'] === $lineId);
        if (! $line) {
            return;
        }

        $this->resource_line_id = (string) $line['id'];
        $this->resource_line_snapshot = [
            'countryCode' => $line['countryCode'] ?? null,
            'cityCode' => $line['cityCode'] ?? null,
            'businessTypeCode' => $line['businessTypeCode'] ?? null,
            'ispType' => $line['ispType'] ?? null,
            'price' => $line['price'] ?? null,
            'currency' => $line['currency'] ?? null,
        ];
        $this->lineSearchResults = [];
    }

    public function clearSelectedLine(): void
    {
        $this->resource_line_id = '';
        $this->resource_line_snapshot = [];
    }

    /**
     * [新购数, 续费数] — historical count of paid orders of each type
     * against this plan, shown in the list as a quick health signal.
     */
    private function planStats(Plan $plan): array
    {
        return [
            'new' => Order::where('plan_id', $plan->id)->where('type', Order::TYPE_NEW)->where('status', Order::STATUS_PAID)->count(),
            'renewal' => Order::where('plan_id', $plan->id)->where('type', Order::TYPE_RENEWAL)->where('status', Order::STATUS_PAID)->count(),
        ];
    }

    public function with(): array
    {
        $plans = Plan::orderBy('sort')->paginate(20);

        return [
            'plans' => $plans,
            'stats' => $plans->mapWithKeys(fn ($plan) => [$plan->id => $this->planStats($plan)]),
            'groups' => ServerGroup::orderBy('id')->get(),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">套餐管理</h1>
        @unless ($showForm)
            <button wire:click="create" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-3 py-1.5">新建套餐</button>
        @endunless
    </div>

    @if ($successMessage)
        <div class="text-sm text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-500/10 border border-green-100 dark:border-green-500/20 rounded-lg p-3">{{ $successMessage }}</div>
    @endif
    @if ($error)
        <div class="text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10 border border-red-100 dark:border-red-500/20 rounded-lg p-3">{{ $error }}</div>
    @endif

    @if ($showForm)
        <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 space-y-4">
            <h2 class="font-medium text-gray-900 dark:text-gray-100">{{ $editingId ? '编辑套餐' : '新建套餐' }}</h2>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">套餐名称</label>
                    <input type="text" wire:model="name" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('name') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">标签</label>
                    <div class="flex flex-wrap gap-1.5 border border-gray-300 dark:border-gray-700 dark:bg-gray-800 rounded-lg px-2 py-1.5">
                        @foreach ($tags as $i => $tag)
                            <span class="inline-flex items-center gap-1 text-xs bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded-full px-2 py-0.5">
                                {{ $tag }}
                                <button type="button" wire:click="removeTag({{ $i }})" class="text-gray-400 hover:text-red-500">&times;</button>
                            </span>
                        @endforeach
                        <input
                            type="text" wire:model="tagInput" wire:keydown.enter.prevent="addTag"
                            placeholder="输入标签后按回车确认" class="flex-1 min-w-[8rem] bg-transparent text-sm outline-none py-0.5 dark:text-gray-100"
                        >
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">类型</label>
                    <select wire:model.live="type" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        <option value="shared">机房共享线路</option>
                        <option value="dedicated_isp">静态 ISP 专属线路</option>
                    </select>
                </div>
                @if ($type === 'shared')
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">服务器分组</label>
                        <div class="flex gap-2">
                            <select wire:model="group_id" class="flex-1 border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                                @foreach ($groups as $group)
                                    <option value="{{ $group->id }}">{{ $group->name }}</option>
                                @endforeach
                            </select>
                            <button type="button" wire:click="$set('showAddGroupModal', true)" class="text-xs border border-gray-300 dark:border-gray-700 rounded-lg px-3 text-gray-600 dark:text-gray-300 hover:border-gray-900 dark:hover:border-gray-400">+ 添加分组</button>
                        </div>
                    </div>
                @endif
            </div>

            <div class="grid grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">流量（GB）</label>
                    <input type="text" wire:model="transfer_enable_gb" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('transfer_enable_gb') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">速度限制（留空不限）</label>
                    <div class="relative">
                        <input type="number" wire:model="speed_limit" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm pr-14">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400">MBPS</span>
                    </div>
                </div>
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">设备数限制（留空不限）</label>
                    <input type="number" wire:model="device_limit" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">容量限制（留空不限）</label>
                    <div class="relative">
                        <input type="number" wire:model="capacity_limit" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm pr-8">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400">人</span>
                    </div>
                </div>
            </div>

            @if ($type === 'shared')
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">流量重置方式</label>
                    <select wire:model="traffic_reset_mode" class="w-full max-w-xs border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        <option value="anniversary">每月购买日</option>
                        <option value="never">不重置</option>
                    </select>
                </div>
            @endif

            @if ($type === 'dedicated_isp')
                <div class="border border-gray-200 dark:border-gray-800 rounded-lg p-4 space-y-3">
                    <h3 class="text-sm font-medium text-gray-900 dark:text-gray-100">开通渠道</h3>

                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" wire:model.live="dynamicMode">
                        客户下单时自己选地区（动态线路，覆盖全部 onip.cc 城市，不用逐条建套餐）
                    </label>

                    @if ($dynamicMode)
                        <div>
                            <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">价格档（ISP 类型）</label>
                            <select wire:model="dynamicIspType" class="w-full max-w-xs border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                                <option value="2">ispType 2 · 标准线路（覆盖更广）</option>
                                <option value="1">ispType 1 · 经济线路（成本更低）</option>
                            </select>
                            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">客户购买这个套餐时会自己搜索、选择具体城市和用途，这里只固定价格档。</p>
                        </div>

                        <div class="border border-gray-200 dark:border-gray-800 rounded-lg p-3 space-y-2">
                            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                <input type="checkbox" wire:model.live="autoPricingEnabled">
                                自动定价（onip 有货的新国家，没手动配价也能自动上架售卖）
                            </label>
                            @if ($autoPricingEnabled)
                                <div class="flex flex-wrap items-center gap-3 text-sm">
                                    <label class="text-xs text-gray-500 dark:text-gray-400">中转成本 ¥/月</label>
                                    <input type="number" min="0" wire:model="autoPricingRelay" class="w-20 border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-2 py-1.5">
                                    <label class="text-xs text-gray-500 dark:text-gray-400">利润 ¥/月</label>
                                    <input type="number" min="0" wire:model="autoPricingProfit" class="w-20 border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-2 py-1.5">
                                    <label class="text-xs text-gray-500 dark:text-gray-400">四舍五入到 ¥</label>
                                    <input type="number" min="1" wire:model="autoPricingRound" class="w-20 border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-2 py-1.5">
                                    <label class="text-xs text-gray-500 dark:text-gray-400">每月加减价 ¥</label>
                                    <input type="number" wire:model="autoPricingMonthlyAdjustment" class="w-20 border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-2 py-1.5">
                                </div>
                                <div class="flex flex-wrap items-center gap-3 text-sm">
                                    <span class="text-xs text-gray-500 dark:text-gray-400">长周期优惠 %off：</span>
                                    <label class="text-xs text-gray-500 dark:text-gray-400">季付</label>
                                    <input type="number" min="0" max="100" wire:model="autoPricingDiscQuarter" class="w-16 border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-2 py-1.5">
                                    <label class="text-xs text-gray-500 dark:text-gray-400">半年</label>
                                    <input type="number" min="0" max="100" wire:model="autoPricingDiscHalf" class="w-16 border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-2 py-1.5">
                                    <label class="text-xs text-gray-500 dark:text-gray-400">年付</label>
                                    <input type="number" min="0" max="100" wire:model="autoPricingDiscYear" class="w-16 border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-2 py-1.5">
                                </div>
                                <p class="text-xs text-gray-400 dark:text-gray-500">
                                    月付 = onip 成本 + 中转 ¥{{ $autoPricingRelay ?: 15 }} + 利润 ¥{{ $autoPricingProfit ?: 20 }}，四舍五入到 ¥{{ $autoPricingRound ?: 5 }}（结尾 0/5）。
                                    每月先加减 ¥{{ $autoPricingMonthlyAdjustment ?: 0 }}；季付 = 月付×3、半年 = ×6、年付 = ×12，再各打 {{ $autoPricingDiscQuarter ?: 0 }}/{{ $autoPricingDiscHalf ?: 0 }}/{{ $autoPricingDiscYear ?: 0 }}% 优惠。
                                    手动配价的国家仍以手动价为准；没配的走这个公式。关掉则只卖手动配价的国家。
                                </p>
                            @endif
                        </div>
                    @elseif ($resource_line_id !== '')
                        <div class="flex items-center justify-between bg-green-50 dark:bg-green-500/10 border border-green-100 dark:border-green-500/20 rounded-lg px-3 py-2">
                            <div class="text-xs text-gray-700 dark:text-gray-300">
                                <span class="font-medium text-green-700 dark:text-green-400">已选择真实线路（onip.cc）</span>
                                · {{ $resource_line_snapshot['countryCode'] ?? '' }}/{{ $resource_line_snapshot['cityCode'] ?? '' }}
                                · {{ $resource_line_snapshot['businessTypeCode'] ?? '' }}
                                · ¥{{ $resource_line_snapshot['price'] ?? '?' }}
                                · lineId: <span class="font-mono">{{ $resource_line_id }}</span>
                            </div>
                            <button type="button" wire:click="clearSelectedLine" class="text-xs text-red-600 dark:text-red-400 hover:underline">清除</button>
                        </div>
                    @else
                        <p class="text-xs text-gray-400 dark:text-gray-500">下面搜真实线路选一个（推荐），或者留空用下面的"本地模拟"字段（仅用于开发测试，不产生真实费用）。</p>

                        <div class="grid grid-cols-4 gap-3">
                            <input type="text" wire:model="lineSearchCountry" placeholder="国家代码 USA" class="border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                            <input type="text" wire:model="lineSearchCity" placeholder="城市代码（可选）" class="border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                            <input type="text" wire:model="lineSearchBusinessType" placeholder="业务类型（可选，如 other）" class="border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                            <select wire:model="lineSearchIspType" class="border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                                <option value="">ISP类型：不限</option>
                                <option value="2">ispType 2 · 有明码价（可售）</option>
                                <option value="1">ispType 1 · 按需报价（无明码价）</option>
                            </select>
                        </div>
                        <button type="button" wire:click="searchOnipLines" class="text-xs bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-3 py-1.5">搜索真实线路</button>

                        @if ($lineSearchError)
                            <p class="text-xs text-red-600 dark:text-red-400">{{ $lineSearchError }}</p>
                        @endif

                        @if (count($lineSearchResults))
                            <div class="max-h-64 overflow-y-auto border border-gray-200 dark:border-gray-800 rounded-lg">
                                <table class="w-full text-xs">
                                    <thead class="bg-gray-50 dark:bg-gray-800/60 text-gray-500 dark:text-gray-400 sticky top-0">
                                        <tr>
                                            <th class="text-left px-2 py-1.5">地区</th>
                                            <th class="text-left px-2 py-1.5">业务类型</th>
                                            <th class="text-left px-2 py-1.5">ISP</th>
                                            <th class="text-left px-2 py-1.5">价格</th>
                                            <th class="text-left px-2 py-1.5">库存</th>
                                            <th class="text-left px-2 py-1.5">状态</th>
                                            <th class="px-2 py-1.5"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($lineSearchResults as $line)
                                            <tr class="border-t border-gray-100 dark:border-gray-800">
                                                <td class="px-2 py-1.5 text-gray-700 dark:text-gray-300">{{ $line['countryCode'] ?? '' }}/{{ $line['cityCode'] ?? '' }}</td>
                                                <td class="px-2 py-1.5 text-gray-500 dark:text-gray-400 font-mono">{{ $line['businessTypeCode'] ?? '' }}</td>
                                                <td class="px-2 py-1.5 text-gray-500 dark:text-gray-400">{{ ['无','广播','原生'][$line['ispType'] ?? 0] ?? '-' }}</td>
                                                <td class="px-2 py-1.5 text-gray-700 dark:text-gray-300">¥{{ $line['price'] ?? '?' }}</td>
                                                <td class="px-2 py-1.5 text-gray-500 dark:text-gray-400">{{ $line['quantity'] ?? '-' }}</td>
                                                <td class="px-2 py-1.5">
                                                    @if (($line['active'] ?? false) && ($line['status'] ?? 1) === 0)
                                                        <span class="text-green-600 dark:text-green-400">可用</span>
                                                    @else
                                                        <span class="text-gray-400">不可用</span>
                                                    @endif
                                                </td>
                                                <td class="px-2 py-1.5 text-right">
                                                    <button type="button" wire:click="selectOnipLine('{{ $line['id'] }}')" class="text-blue-600 dark:text-blue-400 hover:underline">选择</button>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        <details class="pt-1">
                            <summary class="text-xs text-gray-400 dark:text-gray-500 cursor-pointer">本地模拟字段（开发测试用，不填真实 lineId 时才生效）</summary>
                            <div class="grid grid-cols-3 gap-4 mt-2">
                                <div>
                                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">线路商（provider）</label>
                                    <input type="text" wire:model="resource_provider" placeholder="stub" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">国家</label>
                                    <input type="text" wire:model="resource_country" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">城市</label>
                                    <input type="text" wire:model="resource_city" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                                </div>
                            </div>
                        </details>
                    @endif
                </div>
            @endif

            <div>
                <label class="block text-sm text-gray-600 dark:text-gray-400 mb-2">价格设置（元，留空则不支持该周期）</label>
                <div class="grid grid-cols-6 gap-4">
                    <div>
                        <label class="block text-xs text-gray-400 dark:text-gray-500 mb-1">月付</label>
                        <input type="text" wire:model="prices.month" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 dark:text-gray-500 mb-1">季付</label>
                        <input type="text" wire:model="prices.quarter" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 dark:text-gray-500 mb-1">半年付</label>
                        <input type="text" wire:model="prices.half_year" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 dark:text-gray-500 mb-1">年付</label>
                        <input type="text" wire:model="prices.year" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 dark:text-gray-500 mb-1">两年付</label>
                        <input type="text" wire:model="prices.two_year" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs text-gray-400 dark:text-gray-500 mb-1">三年付</label>
                        <input type="text" wire:model="prices.three_year" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    </div>
                </div>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">上面是<strong>基础价（默认）</strong>。静态ISP选地区时，没单独定价的国家用这个。</p>
            </div>

            @if ($type === 'dedicated_isp' && $dynamicMode)
                <div class="border-t border-gray-100 dark:border-gray-800 pt-4">
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">按国家定价（元，选填）</label>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mb-2">给特定国家单独定价——客户选到该国的线路就按这里的价。国家代码用 onip 的 3 位码（如 USA / GBR / JPN）。某周期留空：开了自动定价就按公式算，否则该周期不售卖。</p>
                    <div class="space-y-2">
                        @foreach ($countryPrices as $i => $row)
                            <div class="flex items-center gap-2">
                                <input type="text" wire:model="countryPrices.{{ $i }}.country" placeholder="国家(如 GBR)" class="w-28 border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-2 py-1.5 text-sm font-mono uppercase">
                                <input type="text" wire:model="countryPrices.{{ $i }}.month" placeholder="月" class="w-20 border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-2 py-1.5 text-sm">
                                <input type="text" wire:model="countryPrices.{{ $i }}.quarter" placeholder="季" class="w-20 border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-2 py-1.5 text-sm">
                                <input type="text" wire:model="countryPrices.{{ $i }}.half_year" placeholder="半年" class="w-20 border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-2 py-1.5 text-sm">
                                <input type="text" wire:model="countryPrices.{{ $i }}.year" placeholder="年" class="w-20 border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-2 py-1.5 text-sm">
                                <button type="button" wire:click="removeCountryPrice({{ $i }})" class="text-xs text-red-600 dark:text-red-400 px-1">删</button>
                            </div>
                        @endforeach
                    </div>
                    <button type="button" wire:click="addCountryPrice" class="mt-2 text-xs border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-3 py-1.5">+ 添加国家定价</button>
                </div>
            @endif

            @if ($type === 'shared')
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-2">加油包（元/GB，留空则不开放该包）</label>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs text-gray-400 dark:text-gray-500 mb-1">流量包</label>
                            <input type="text" wire:model="traffic_pack_price" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">一次性流量包，无时间限制</p>
                        </div>
                        <div>
                            <label class="block text-xs text-gray-400 dark:text-gray-500 mb-1">重置包</label>
                            <input type="text" wire:model="traffic_reset_pack_price" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">重置流量包，可多次使用</p>
                        </div>
                    </div>
                </div>
            @endif

            <div>
                <div class="flex items-center justify-between mb-1">
                    <label class="block text-sm text-gray-600 dark:text-gray-400">套餐说明（客户购买页会展示这段文字）</label>
                    <button type="button" wire:click="$toggle('showDescriptionPreview')" class="text-xs text-gray-500 dark:text-gray-400 underline">{{ $showDescriptionPreview ? '返回编辑' : '显示预览' }}</button>
                </div>
                @if ($showDescriptionPreview)
                    <div class="w-full border border-gray-200 dark:border-gray-800 rounded-lg px-3 py-2 text-sm text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-800/60 whitespace-pre-line min-h-[6rem]">{{ $description ?: '（还没写说明）' }}</div>
                @else
                    <textarea wire:model="description" rows="4" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm"></textarea>
                @endif
            </div>

            <div class="flex items-center gap-6 flex-wrap">
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">排序</label>
                    <input type="number" wire:model="sort" class="w-24 border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                </div>
                <label class="flex items-center gap-2 text-sm mt-5">
                    <input type="checkbox" wire:model="show">
                    显示
                </label>
                <label class="flex items-center gap-2 text-sm mt-5">
                    <input type="checkbox" wire:model="sell">
                    新购
                </label>
                <label class="flex items-center gap-2 text-sm mt-5">
                    <input type="checkbox" wire:model="allow_renewal">
                    续费
                </label>
            </div>

            @if ($editingId)
                <label class="flex items-center gap-2 text-sm text-amber-700 dark:text-amber-400">
                    <input type="checkbox" wire:model="forceUpdateExisting">
                    强制更新用户套餐（已订阅这个套餐的用户立即按新参数计算，不用等续费）
                </label>
            @endif

            <div class="flex gap-2 pt-2">
                <button wire:click="save" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">保存</button>
                <button wire:click="cancel" class="text-sm border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-4 py-2">取消</button>
            </div>
        </div>
    @endif

    <table class="w-full text-sm bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl overflow-hidden">
        <thead class="bg-gray-50 dark:bg-gray-800/60 text-gray-500 dark:text-gray-400 text-xs">
            <tr>
                <th class="text-left px-4 py-2">名称</th>
                <th class="text-left px-4 py-2">类型</th>
                <th class="text-left px-4 py-2">流量</th>
                <th class="text-left px-4 py-2">显示</th>
                <th class="text-left px-4 py-2">新购</th>
                <th class="text-left px-4 py-2">续费</th>
                <th class="text-left px-4 py-2">统计</th>
                <th class="text-left px-4 py-2"></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($plans as $plan)
                <tr class="border-t border-gray-100 dark:border-gray-800">
                    <td class="px-4 py-2 text-gray-700 dark:text-gray-300">{{ $plan->name }}</td>
                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $plan->type === 'shared' ? '机房共享' : '静态 ISP' }}</td>
                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ round($plan->transfer_enable / 1073741824) }}GB</td>
                    <td class="px-4 py-2">
                        <button type="button" wire:click="toggleShow({{ $plan->id }})" class="text-xs rounded-full px-2 py-1 {{ $plan->show ? 'bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400' : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400' }}">{{ $plan->show ? '开' : '关' }}</button>
                    </td>
                    <td class="px-4 py-2">
                        <button type="button" wire:click="toggleSell({{ $plan->id }})" class="text-xs rounded-full px-2 py-1 {{ $plan->sell ? 'bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400' : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400' }}">{{ $plan->sell ? '开' : '关' }}</button>
                    </td>
                    <td class="px-4 py-2">
                        <button type="button" wire:click="toggleRenewal({{ $plan->id }})" class="text-xs rounded-full px-2 py-1 {{ $plan->allow_renewal ? 'bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400' : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400' }}">{{ $plan->allow_renewal ? '开' : '关' }}</button>
                    </td>
                    <td class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400">新购 {{ $stats[$plan->id]['new'] }} · 续费 {{ $stats[$plan->id]['renewal'] }}</td>
                    <td class="px-4 py-2 text-right space-x-2">
                        <button wire:click="edit({{ $plan->id }})" class="text-xs text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100">编辑</button>
                        <button wire:click="delete({{ $plan->id }})" wire:confirm="确定删除这个套餐？" class="text-xs text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300">删除</button>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{ $plans->links() }}

    @if ($showAddGroupModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div wire:click="$set('showAddGroupModal', false)" class="absolute inset-0 bg-black/50"></div>
            <div class="relative bg-white dark:bg-gray-900 rounded-xl w-full max-w-sm p-5 space-y-3">
                <h3 class="text-sm font-medium text-gray-900 dark:text-gray-100">新建服务器分组</h3>
                <input type="text" wire:model="newGroupName" placeholder="分组名称" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                <div class="flex gap-2">
                    <button wire:click="createGroup" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">创建</button>
                    <button wire:click="$set('showAddGroupModal', false)" class="text-sm border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-4 py-2">取消</button>
                </div>
            </div>
        </div>
    @endif
</div>
