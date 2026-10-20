<?php

use App\Services\AgentService;
use App\Services\OnipProvisioningService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app', ['title' => '代理中心'])] class extends Component
{
    use WithPagination;

    public string $faceAmount = '10';
    public string $quantity = '1';
    public string $successMessage = '';
    public string $error = '';
    public array $lastCodes = [];

    // 代客开通
    public string $customerEmail = '';
    public ?int $openPlanId = null;
    public string $openPeriod = 'month';

    // 动态 ISP 选地区（代客开通任选地区套餐时）—— 从购买页选线器搬来
    public ?string $pickContinent = null;
    public array $continentCities = [];
    public string $lineSearchCity = '';
    public string $lineSearchCountry = '';
    public string $lineSearchBusinessType = '';
    public array $lineSearchResults = [];
    public string $lineSearchError = '';
    public string $selectedLineId = '';
    public array $selectedLineSnapshot = [];
    public string $selectedCityName = '';

    private const PERIOD_LABELS = ['month' => '月付', 'quarter' => '季付', 'half_year' => '半年付', 'year' => '年付'];
    private const ONIP_SEARCH_PAGE_SIZE = 20;

    /**
     * onip city codes grouped into browsing buckets (first 3 chars = ISO3
     * country) — same map the buy page uses.
     */
    private const CONTINENTS = [
        '北美' => ['USA', 'CAN', 'MEX'],
        '欧洲' => ['GBR', 'DEU', 'FRA', 'ESP', 'ITA', 'NLD'],
        '亚太' => ['CHN', 'JPN', 'KOR', 'SGP', 'MYS', 'PHL', 'VNM', 'IDN', 'IND', 'THA', 'AUS'],
        '中东/非洲' => ['ARE', 'SAU', 'ZAF'],
        '南美' => ['BRA', 'CHL'],
    ];

    public function continentNames(): array
    {
        return array_keys(self::CONTINENTS);
    }

    public function periodLabel(string $p): string
    {
        return self::PERIOD_LABELS[$p] ?? $p;
    }

    public function mount(): void
    {
        // 代理专属：非代理直接 403（路由是 auth 的，这里再挡一层）
        abort_unless(auth()->user()?->isAgent(), 403);
    }

    /** Discounted total the current inputs would cost the agent (cents). */
    public function previewCost(): int
    {
        $face = (int) round(((float) $this->faceAmount) * 100);
        $qty = max(0, (int) $this->quantity);

        if ($face <= 0 || $qty <= 0) {
            return 0;
        }

        return app(AgentService::class)->unitCost(auth()->user(), $face) * $qty;
    }

    public function generate(): void
    {
        $this->successMessage = '';
        $this->error = '';
        $this->lastCodes = [];

        $face = (int) round(((float) $this->faceAmount) * 100);
        $qty = (int) $this->quantity;

        try {
            $cards = app(AgentService::class)->generateCards(auth()->user()->fresh(), $face, $qty);
            $this->lastCodes = $cards->pluck('code')->all();
            $this->successMessage = '已生成 '.$cards->count().' 张卡密，扣除余额 ¥'.number_format($cards->sum('agent_cost') / 100, 2).'，把下面的卡密卖给客户即可';
        } catch (ValidationException $e) {
            $this->error = collect($e->errors())->flatten()->first();
        }
    }

    /** Switching the plan resets any in-progress region pick. */
    public function updatedOpenPlanId(): void
    {
        $this->resetPicker();
    }

    private function resetPicker(): void
    {
        $this->pickContinent = null;
        $this->continentCities = [];
        $this->lineSearchResults = [];
        $this->lineSearchError = '';
        $this->lineSearchCity = '';
        $this->selectedLineId = '';
        $this->selectedLineSnapshot = [];
        $this->selectedCityName = '';
    }

    // --- 动态 ISP 选地区（搬自购买页选线器）---

    public function browseContinent(string $continent): void
    {
        $this->lineSearchError = '';
        $this->pickContinent = $continent;
        $this->continentCities = [];
        $this->lineSearchResults = [];
        $this->lineSearchCity = '';

        $service = app(OnipProvisioningService::class);
        if (! $service->isConfigured()) {
            $this->lineSearchError = '线路查询暂时不可用，请稍后再试';

            return;
        }

        $countryCodes = self::CONTINENTS[$continent] ?? [];
        $ispType = (int) (\App\Models\Plan::find($this->openPlanId)?->resource_profile['ispType'] ?? 2);

        try {
            $locations = $service->getAvailableLocations(['ispType' => $ispType]);
            $pricedCountries = $this->pricedCountryCodes();
            $this->continentCities = collect($locations)
                ->filter(fn ($loc) => in_array(substr($loc['code'] ?? '', 0, 3), $countryCodes, true))
                ->filter(fn ($loc) => $pricedCountries === null || in_array(substr($loc['code'] ?? '', 0, 3), $pricedCountries, true))
                ->filter(fn ($loc) => $this->locationHasAvailableLines($service, $loc, $ispType))
                ->map(fn ($loc) => ['code' => $loc['code'], 'name' => $loc['name']])
                ->values()
                ->all();

            if (empty($this->continentCities)) {
                $this->lineSearchError = '这个地区暂时没有有货的城市';
            }
        } catch (\Throwable $e) {
            $this->lineSearchError = '获取城市列表失败，请稍后再试';
        }
    }

    private function locationHasAvailableLines(OnipProvisioningService $service, array $location, int $ispType): bool
    {
        $cidrs = collect($location['cidrs'] ?? []);
        if ($cidrs->isNotEmpty()) {
            return $cidrs->sum(fn ($c) => (int) ($c['availableCount'] ?? 0)) > 0;
        }

        $cityCode = $location['code'] ?? null;
        if (! $cityCode || ! ($location['active'] ?? false)) {
            return false;
        }

        return Cache::remember("isp_city_stock_{$ispType}_{$cityCode}", 600, function () use ($service, $cityCode, $ispType) {
            try {
                $result = $service->searchLines([
                    'cityCode' => $cityCode,
                    'ispType' => $ispType,
                    'current' => 0,
                    'size' => self::ONIP_SEARCH_PAGE_SIZE,
                ]);

                return collect($result['records'] ?? [])->contains(fn ($line) => ($line['active'] ?? false)
                    && ($line['status'] ?? 1) === 0
                    && ($line['price'] ?? null) !== null
                    && (int) ($line['quantity'] ?? 0) > 0);
            } catch (\Throwable) {
                return false;
            }
        });
    }

    public function reselectCity(): void
    {
        $this->selectedLineId = '';
        $this->selectedLineSnapshot = [];
        $this->selectedCityName = '';
    }

    public function backToContinents(): void
    {
        $this->pickContinent = null;
        $this->continentCities = [];
        $this->lineSearchResults = [];
        $this->lineSearchError = '';
        $this->selectedLineId = '';
        $this->selectedLineSnapshot = [];
        $this->selectedCityName = '';
    }

    public function browseCity(string $cityCode, string $cityName = ''): void
    {
        $this->lineSearchCity = $cityCode;
        $this->lineSearchCountry = '';
        $this->selectedCityName = $cityName;
        $this->searchLines();
        $this->autoSelectBestLine();
    }

    private function autoSelectBestLine(): void
    {
        $best = collect($this->lineSearchResults)
            ->filter(fn ($line) => ($line['active'] ?? false) && ($line['status'] ?? 1) === 0)
            ->sortByDesc(fn ($line) => $line['quantity'] ?? 0)
            ->first();

        if ($best) {
            $this->selectLine((string) $best['id']);
        }
    }

    public function searchLines(): void
    {
        $this->lineSearchError = '';
        $this->lineSearchResults = [];

        $plan = \App\Models\Plan::find($this->openPlanId);
        $service = app(OnipProvisioningService::class);

        if (! $plan || ! $service->isConfigured()) {
            $this->lineSearchError = '线路查询暂时不可用，请稍后再试';

            return;
        }

        try {
            $result = $service->searchLines(array_filter([
                'countryCode' => $this->lineSearchCountry ?: null,
                'cityCode' => $this->lineSearchCity ?: null,
                'businessType' => $this->lineSearchBusinessType ?: null,
                'ispType' => $plan->resource_profile['ispType'] ?? 2,
                'current' => 0,
                'size' => self::ONIP_SEARCH_PAGE_SIZE,
            ]));

            $pricedCountries = $this->pricedCountryCodes();
            $this->lineSearchResults = collect($result['records'] ?? [])
                ->filter(fn ($l) => ($l['price'] ?? null) !== null)
                ->filter(fn ($l) => $pricedCountries === null || in_array($l['countryCode'] ?? null, $pricedCountries, true))
                ->values()
                ->all();

            // Cache each line's real onip cost server-side so the order path
            // prices from a source the client can't tamper with.
            foreach ($this->lineSearchResults as $line) {
                $service->cacheLine($line);
            }

            if (empty($this->lineSearchResults)) {
                $this->lineSearchError = '这个城市暂时没有可售线路，换一个城市再试';
            }
        } catch (\Throwable $e) {
            $this->lineSearchError = '搜索失败，请稍后再试';
        }
    }

    public function selectLine(string $lineId): void
    {
        $line = collect($this->lineSearchResults)->first(fn ($l) => (string) $l['id'] === $lineId);
        if (! $line) {
            return;
        }

        $this->selectedLineId = (string) $line['id'];
        $this->selectedLineSnapshot = [
            'countryCode' => $line['countryCode'] ?? null,
            'cityCode' => $line['cityCode'] ?? null,
            'businessTypeCode' => $line['businessTypeCode'] ?? null,
            'ispType' => $line['ispType'] ?? null,
            'price' => $line['price'] ?? null,
            'currency' => $line['currency'] ?? null,
        ];
    }

    private function pricedCountryCodes(): ?array
    {
        $plan = \App\Models\Plan::find($this->openPlanId);

        if ($plan && $plan->autoPricing()['enabled']) {
            return null;
        }

        $map = $plan?->country_prices ?? [];
        if ($map === []) {
            return null;
        }

        $period = $this->openPeriod ?: 'month';

        return collect($map)->filter(fn ($byPeriod) => ! empty($byPeriod[$period]))->keys()->all();
    }

    /** Discounted cost of the currently-selected 代客开通 plan/period (cents), or null. */
    public function previewOpenCost(): ?int
    {
        $plan = $this->openPlanId ? \App\Models\Plan::find($this->openPlanId) : null;
        if (! $plan) {
            return null;
        }

        $snapshot = $this->selectedLineId !== '' ? $this->selectedLineSnapshot : null;

        return app(AgentService::class)->openCost(auth()->user(), $plan, $this->openPeriod, $snapshot);
    }

    public function openForCustomer(): void
    {
        $this->successMessage = '';
        $this->error = '';

        $customer = \App\Models\User::where('email', strtolower(trim($this->customerEmail)))->first();
        if (! $customer) {
            $this->error = '该邮箱未注册——客户需先自行注册，你才能为其代开通';

            return;
        }

        $plan = \App\Models\Plan::find($this->openPlanId);
        if (! $plan) {
            $this->error = '请选择要开通的套餐';

            return;
        }

        // Dynamic (任选地区) ISP needs a picked line.
        $selectedLine = null;
        if ($plan->isDynamicIsp()) {
            if ($this->selectedLineId === '') {
                $this->error = '请先选择线路地区';

                return;
            }
            $selectedLine = [
                'provider' => 'onip',
                'lineId' => $this->selectedLineId,
                'line_snapshot' => $this->selectedLineSnapshot,
            ];
        }

        try {
            $cost = app(AgentService::class)->openCost(auth()->user(), $plan, $this->openPeriod, $this->selectedLineSnapshot ?: null);
            app(AgentService::class)->openForCustomer(auth()->user()->fresh(), $customer, $plan, $this->openPeriod, $selectedLine);
            $this->successMessage = "已为 {$customer->email} 开通「{$plan->name}」（{$this->periodLabel($this->openPeriod)}），扣除余额 ¥".number_format(($cost ?? 0) / 100, 2);
            $this->customerEmail = '';
            $this->resetPicker();
        } catch (ValidationException $e) {
            $this->error = collect($e->errors())->flatten()->first();
        }
    }

    public function with(): array
    {
        // Fresh read — generate() charges a row-locked copy inside AgentService,
        // so the request's in-memory user has a stale balance otherwise.
        $user = auth()->user()->fresh();
        $all = $user->agentGiftCards()->get();
        $sold = $all->where('redeemed', true);

        return [
            'balance' => $user->balance,
            'discount' => (int) $user->agent_discount,
            'totalCount' => $all->count(),
            'soldCount' => $sold->count(),
            'soldFace' => $sold->sum('amount'),
            'profit' => $sold->sum('amount') - $sold->sum('agent_cost'),
            'cards' => $user->agentGiftCards()->with('redeemedBy')->latest()->paginate(20),
            // 代客开通：所有在售套餐（共享/固定 ISP 直接开；任选地区先选地区）+ 最近代开通记录
            'openPlans' => \App\Models\Plan::where('sell', true)->where('show', true)->orderBy('sort')->get(),
            'agentOrders' => \App\Models\Order::where('paid_by_agent_id', $user->id)->with(['user', 'plan'])->latest()->limit(10)->get(),
        ];
    }
};
?>

<div class="space-y-6">
    <div>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">代理中心</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">用余额按折扣生成卡密，卖给你的客户自行充值。你的进货折扣：<span class="font-medium text-teal-600 dark:text-teal-400">{{ $discount }}% off</span>（面值的 {{ 100 - $discount }}%）。</p>
    </div>

    @if ($successMessage)
        <div class="text-sm text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-500/10 border border-green-100 dark:border-green-500/20 rounded-lg p-3">{{ $successMessage }}</div>
    @endif
    @if ($error)
        <div class="text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-500/10 border border-red-100 dark:border-red-500/20 rounded-lg p-3">{{ $error }}</div>
    @endif

    {{-- 概览 --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        @php
            $tiles = [
                ['label' => '账户余额', 'value' => '¥'.number_format($balance / 100, 2), 'hint' => '进货用；充值请联系站长'],
                ['label' => '累计生成', 'value' => $totalCount.' 张', 'hint' => '你发出的卡密总数'],
                ['label' => '已售（已核销）', 'value' => $soldCount.' 张', 'hint' => '客户已兑换的卡密'],
                ['label' => '预计毛利', 'value' => '¥'.number_format($profit / 100, 2), 'hint' => '已售面值 − 进货成本'],
            ];
        @endphp
        @foreach ($tiles as $t)
            <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-4 bg-white dark:bg-gray-900">
                <div class="text-xs text-gray-400 dark:text-gray-500">{{ $t['label'] }}</div>
                <div class="text-lg font-semibold text-gray-900 dark:text-gray-100 mt-1">{{ $t['value'] }}</div>
                <div class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">{{ $t['hint'] }}</div>
            </div>
        @endforeach
    </div>

    {{-- 生成卡密 --}}
    <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 max-w-xl">
        <h2 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">生成卡密</h2>
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">单张面额（¥）</label>
                <input type="number" min="1" step="1" wire:model.live="faceAmount" class="w-28 border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">数量（张）</label>
                <input type="number" min="1" max="100" wire:model.live="quantity" class="w-24 border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
            </div>
            <button wire:click="generate" wire:loading.attr="disabled" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">生成</button>
        </div>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-3">
            本次进货成本：<span class="font-medium text-gray-900 dark:text-gray-100">¥{{ number_format($this->previewCost() / 100, 2) }}</span>
            （按 {{ 100 - $discount }}% 进货）· 卖出面值合计 ¥{{ number_format(((int) round(((float) $faceAmount) * 100) * max(0, (int) $quantity)) / 100, 2) }}
        </p>

        @if (count($lastCodes))
            <div
                x-data="{ codes: @js($lastCodes), copied: false }"
                class="mt-4 border-t border-gray-100 dark:border-gray-800 pt-3"
            >
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs text-gray-500 dark:text-gray-400">刚生成的卡密（请及时保存）</span>
                    <button type="button" @click="copyToClipboard(codes.join('\n')); copied = true; setTimeout(() => copied = false, 1500)" class="text-xs text-teal-600 dark:text-teal-400 hover:underline">
                        <span x-show="!copied">复制全部</span><span x-show="copied" x-cloak>已复制</span>
                    </button>
                </div>
                <div class="bg-gray-50 dark:bg-gray-800/60 rounded-lg p-3 font-mono text-xs text-gray-700 dark:text-gray-300 space-y-1 max-h-48 overflow-y-auto">
                    @foreach ($lastCodes as $code)
                        <div>{{ $code }}</div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    {{-- 代客开通：用余额按折扣直接给客户账号开通（非动态套餐） --}}
    <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 max-w-xl">
        <h2 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">代客开通</h2>
        <p class="text-xs text-gray-400 dark:text-gray-500 mb-3">用余额按折扣直接给客户账号开通套餐（客户需先自行注册）。任选地区套餐请改用发卡密。</p>

        <div class="space-y-3">
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">客户邮箱</label>
                <input type="email" wire:model="customerEmail" placeholder="客户注册时用的邮箱" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
            </div>
            <div class="flex flex-wrap gap-3">
                <div>
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">套餐</label>
                    <select wire:model.live="openPlanId" class="border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        <option value="">选择套餐</option>
                        @foreach ($openPlans as $p)
                            <option value="{{ $p->id }}">{{ $p->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">周期</label>
                    @php $selPlan = $openPlans->firstWhere('id', (int) $openPlanId); @endphp
                    <select wire:model.live="openPeriod" class="border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        @forelse (($selPlan->prices ?? []) as $period => $price)
                            <option value="{{ $period }}">{{ $this->periodLabel($period) }} · 面价 ¥{{ number_format($price / 100, 2) }}</option>
                        @empty
                            <option value="month">先选套餐</option>
                        @endforelse
                    </select>
                </div>
            </div>

            @php $isDyn = $selPlan?->isDynamicIsp() ?? false; @endphp
            @if ($isDyn)
                {{-- 任选地区套餐：代理替客户选国家/城市 --}}
                <div class="border-t border-gray-100 dark:border-gray-800 pt-3 space-y-2">
                    @if ($selectedLineId !== '')
                        <div class="flex items-center justify-between bg-green-50 dark:bg-green-500/10 border border-green-100 dark:border-green-500/20 rounded-lg px-3 py-2">
                            <span class="text-xs text-gray-700 dark:text-gray-300"><span class="font-medium text-green-700 dark:text-green-400">已选择线路</span> · {{ $selectedCityName }}</span>
                            <button type="button" wire:click="reselectCity" class="text-xs text-red-600 dark:text-red-400 hover:underline">重选</button>
                        </div>
                    @elseif ($pickContinent === null)
                        <p class="text-xs text-gray-500 dark:text-gray-400">先选一个大洲：</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($this->continentNames() as $c)
                                <button type="button" wire:click="browseContinent('{{ $c }}')" wire:loading.attr="disabled" class="text-xs border border-gray-300 dark:border-gray-700 rounded-lg px-3 py-1.5 text-gray-700 dark:text-gray-300 hover:border-gray-900 dark:hover:border-gray-400">{{ $c }}</button>
                            @endforeach
                        </div>
                        @if ($lineSearchError)<p class="text-xs text-red-600 dark:text-red-400">{{ $lineSearchError }}</p>@endif
                    @else
                        <div class="flex items-center justify-between">
                            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $pickContinent }} · 选一个城市：</p>
                            <button type="button" wire:click="backToContinents" class="text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">← 换个大洲</button>
                        </div>
                        @if (count($continentCities))
                            <div class="flex flex-wrap gap-2">
                                @foreach ($continentCities as $city)
                                    <button type="button" wire:click="browseCity('{{ $city['code'] }}', '{{ $city['name'] }}')" wire:loading.attr="disabled" class="text-xs border border-gray-300 dark:border-gray-700 rounded-lg px-3 py-1.5 text-gray-700 dark:text-gray-300 hover:border-gray-900 dark:hover:border-gray-400">{{ $city['name'] }}</button>
                                @endforeach
                            </div>
                        @endif
                        @if ($lineSearchError)<p class="text-xs text-red-600 dark:text-red-400">{{ $lineSearchError }}</p>@endif
                    @endif
                </div>
            @endif

            <p class="text-xs text-gray-500 dark:text-gray-400">
                @if ($this->previewOpenCost() !== null)
                    本次进货成本：<span class="font-medium text-gray-900 dark:text-gray-100">¥{{ number_format($this->previewOpenCost() / 100, 2) }}</span>（按 {{ 100 - $discount }}% 进货，从你余额扣）
                @elseif ($isDyn)
                    选好地区后显示成本
                @else
                    选择套餐与周期后显示成本
                @endif
            </p>
            <button wire:click="openForCustomer" wire:loading.attr="disabled" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">确认开通</button>
        </div>

        @if ($agentOrders->count())
            <div class="mt-4 border-t border-gray-100 dark:border-gray-800 pt-3">
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">最近代开通</p>
                <div class="space-y-1 text-xs">
                    @foreach ($agentOrders as $o)
                        <div class="flex items-center justify-between text-gray-600 dark:text-gray-400">
                            <span class="font-mono truncate">{{ $o->user->email ?? '—' }} · {{ $o->plan->name ?? '' }}</span>
                            <span class="shrink-0 text-gray-400 dark:text-gray-500">{{ $o->created_at->format('m-d H:i') }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    {{-- 卡密列表 --}}
    <div>
        <h2 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">我的卡密</h2>
        <table class="w-full text-sm bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl overflow-hidden">
            <thead class="bg-gray-50 dark:bg-gray-800/60 text-gray-500 dark:text-gray-400 text-xs">
                <tr>
                    <th class="text-left px-4 py-2">卡密</th>
                    <th class="text-left px-4 py-2">面额</th>
                    <th class="text-left px-4 py-2">进货成本</th>
                    <th class="text-left px-4 py-2">状态</th>
                    <th class="text-left px-4 py-2">核销时间</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($cards as $card)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="px-4 py-2 font-mono text-gray-700 dark:text-gray-300">{{ $card->code }}</td>
                        <td class="px-4 py-2 text-gray-700 dark:text-gray-300">¥{{ number_format($card->amount / 100, 2) }}</td>
                        <td class="px-4 py-2 text-gray-500 dark:text-gray-400">¥{{ number_format(($card->agent_cost ?? 0) / 100, 2) }}</td>
                        <td class="px-4 py-2">
                            @if ($card->redeemed)
                                <span class="text-xs bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400 rounded-full px-2 py-0.5">已售</span>
                            @else
                                <span class="text-xs bg-gray-100 dark:bg-gray-800 text-gray-500 rounded-full px-2 py-0.5">未售</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-gray-400 dark:text-gray-500 text-xs">{{ $card->redeemed_at?->format('Y-m-d H:i') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400 dark:text-gray-500">还没有生成过卡密</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="mt-3">{{ $cards->links() }}</div>
    </div>
</div>
