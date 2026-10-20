<?php

use App\Models\Coupon;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin', ['title' => '优惠券管理'])] class extends Component
{
    use WithPagination;

    public bool $showForm = false;
    public ?int $editingId = null;

    public string $code = '';
    public string $type = 'percent';
    public string $value = '';
    public ?int $max_uses = null;
    public ?int $per_user_limit = null;
    public string $expires_at = '';
    public bool $enabled = true;

    public string $successMessage = '';

    // 批量生成
    public bool $batchMode = false;
    public int $batchCount = 10;
    public string $batchPrefix = '';
    public array $generatedCodes = [];

    public function create(): void
    {
        $this->resetForm();
        $this->code = strtoupper(\Illuminate\Support\Str::random(8));
        $this->showForm = true;
    }

    public function batchCreate(): void
    {
        $this->resetForm();
        $this->generatedCodes = [];
        $this->batchMode = true;
        $this->batchCount = 10;
        $this->batchPrefix = '';
        $this->showForm = true;
    }

    /**
     * Generate N distinct single-use coupons sharing the same discount — for
     * campaigns/giveaways where each customer gets their own code (harder to
     * share, trackable) rather than one code with a use-count.
     */
    public function generateBatch(): void
    {
        $this->validate([
            'type' => ['required', 'in:fixed,percent'],
            'value' => ['required', 'numeric', 'min:0.01'],
            'batchCount' => ['required', 'integer', 'min:1', 'max:500'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'batchPrefix' => ['nullable', 'string', 'max:16'],
        ]);

        $value = $this->type === 'percent' ? (int) $this->value : (int) round(((float) $this->value) * 100);
        $prefix = strtoupper(trim($this->batchPrefix));
        $codes = [];

        for ($i = 0; $i < $this->batchCount; $i++) {
            do {
                $code = $prefix.strtoupper(\Illuminate\Support\Str::random(8));
            } while (in_array($code, $codes, true) || Coupon::where('code', $code)->exists());

            Coupon::create([
                'code' => $code, 'type' => $this->type, 'value' => $value,
                'max_uses' => $this->max_uses ?: 1, // 批量码默认单次使用
                'expires_at' => $this->expires_at ?: null, 'enabled' => true,
            ]);
            $codes[] = $code;
        }

        $this->generatedCodes = $codes;
        $this->successMessage = "已批量生成 {$this->batchCount} 张优惠券（默认单次使用，可复制下方列表分发）";
        $this->showForm = false;
        $this->batchMode = false;
    }

    public function edit(int $couponId): void
    {
        $coupon = Coupon::findOrFail($couponId);

        $this->editingId = $coupon->id;
        $this->code = $coupon->code;
        $this->type = $coupon->type;
        $this->value = $coupon->type === 'percent' ? (string) $coupon->value : number_format($coupon->value / 100, 2, '.', '');
        $this->max_uses = $coupon->max_uses;
        $this->per_user_limit = $coupon->per_user_limit;
        $this->expires_at = $coupon->expires_at?->format('Y-m-d') ?? '';
        $this->enabled = $coupon->enabled;

        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate([
            'code' => ['required', 'string', 'max:64', 'unique:coupons,code,' . $this->editingId],
            'type' => ['required', 'in:fixed,percent'],
            'value' => ['required', 'numeric', 'min:0.01'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'per_user_limit' => ['nullable', 'integer', 'min:1'],
        ]);

        $data = [
            'code' => strtoupper($this->code),
            'type' => $this->type,
            'value' => $this->type === 'percent' ? (int) $this->value : (int) round(((float) $this->value) * 100),
            'max_uses' => $this->max_uses ?: null,
            'per_user_limit' => $this->per_user_limit ?: null,
            'expires_at' => $this->expires_at ?: null,
            'enabled' => $this->enabled,
        ];

        if ($this->editingId) {
            Coupon::findOrFail($this->editingId)->update($data);
            $this->successMessage = '优惠券已更新';
        } else {
            Coupon::create($data);
            $this->successMessage = '优惠券已创建';
        }

        $this->showForm = false;
        $this->resetForm();
    }

    public function delete(int $couponId): void
    {
        Coupon::findOrFail($couponId)->delete();
        $this->successMessage = '优惠券已删除';
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->batchMode = false;
        $this->code = '';
        $this->type = 'percent';
        $this->value = '';
        $this->max_uses = null;
        $this->per_user_limit = null;
        $this->expires_at = '';
        $this->enabled = true;
    }

    public function with(): array
    {
        return [
            'coupons' => Coupon::orderByDesc('created_at')->paginate(20),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">优惠券管理</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">创建折扣码，客户在购买订阅时输入即可抵扣。</p>
        </div>
        @unless ($showForm)
            <div class="flex gap-2 shrink-0">
                <button wire:click="batchCreate" class="text-sm border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-3 py-1.5">批量生成</button>
                <button wire:click="create" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-3 py-1.5">新建优惠券</button>
            </div>
        @endunless
    </div>

    @if ($successMessage)
        <div class="text-sm text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-500/10 border border-green-100 dark:border-green-500/20 rounded-lg p-3">{{ $successMessage }}</div>
    @endif

    @if (! empty($generatedCodes))
        <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-4 bg-white dark:bg-gray-900" x-data="{ copied: false }">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-gray-900 dark:text-gray-100">刚生成的 {{ count($generatedCodes) }} 张券码（复制分发给客户）</span>
                <button type="button" @click="navigator.clipboard.writeText($refs.codes.value); copied = true; setTimeout(() => copied = false, 1500)" class="text-xs border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-3 py-1">
                    <span x-show="!copied">复制全部</span><span x-show="copied" x-cloak>已复制</span>
                </button>
            </div>
            <textarea x-ref="codes" readonly rows="6" class="w-full border border-gray-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-xs font-mono">{{ implode("\n", $generatedCodes) }}</textarea>
        </div>
    @endif

    @if ($showForm)
        <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 space-y-4">
            <h2 class="font-medium text-gray-900 dark:text-gray-100">{{ $batchMode ? '批量生成优惠券' : ($editingId ? '编辑优惠券' : '新建优惠券') }}</h2>

            <div class="grid grid-cols-2 gap-4">
                @if ($batchMode)
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">生成数量（1-500）</label>
                        <input type="number" min="1" max="500" wire:model="batchCount" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        @error('batchCount') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div>
                        <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">兑换码</label>
                        <input type="text" wire:model="code" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono uppercase">
                        @error('code') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                    </div>
                @endif
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">折扣类型</label>
                    <select wire:model.live="type" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        <option value="percent">百分比折扣</option>
                        <option value="fixed">固定金额</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">
                        {{ $type === 'percent' ? '折扣百分比（1-100）' : '折扣金额（元）' }}
                    </label>
                    <input type="text" wire:model="value" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('value') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">最大使用次数（总量，留空不限）</label>
                    <input type="number" wire:model="max_uses" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">每人可用次数（留空不限）</label>
                    <input type="number" wire:model="per_user_limit" placeholder="如 1 = 每位用户仅一次" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">过期日期（留空不限）</label>
                    <input type="date" wire:model="expires_at" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                </div>
            </div>

            @if ($batchMode)
                <div class="max-w-xs">
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">码前缀（选填，如 SUMMER）</label>
                    <input type="text" wire:model="batchPrefix" placeholder="留空则纯随机码" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono uppercase">
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">批量码默认「单次使用」——一人一码，用完即失效（比一个码用N次更防转发）。</p>
                    @error('batchPrefix') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>
            @else
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="enabled">
                    启用
                </label>
            @endif

            <div class="flex gap-2 pt-2">
                @if ($batchMode)
                    <button wire:click="generateBatch" wire:loading.attr="disabled" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">生成</button>
                @else
                    <button wire:click="save" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">保存</button>
                @endif
                <button wire:click="cancel" class="text-sm border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-4 py-2">取消</button>
            </div>
        </div>
    @endif

    <table class="w-full text-sm bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl overflow-hidden">
        <thead class="bg-gray-50 dark:bg-gray-800/60 text-gray-500 dark:text-gray-400 text-xs">
            <tr>
                <th class="text-left px-4 py-2">状态</th>
                <th class="text-left px-4 py-2">兑换码</th>
                <th class="text-left px-4 py-2">折扣</th>
                <th class="text-left px-4 py-2">已使用</th>
                <th class="text-left px-4 py-2">过期时间</th>
                <th class="text-left px-4 py-2"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($coupons as $coupon)
                <tr class="border-t border-gray-100 dark:border-gray-800">
                    <td class="px-4 py-2">
                        @if ($coupon->isValid())
                            <span class="text-xs bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400 rounded-full px-2 py-1">有效</span>
                        @else
                            <span class="text-xs bg-gray-100 dark:bg-gray-800 text-gray-500 rounded-full px-2 py-1">失效</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-gray-700 dark:text-gray-300 font-mono">{{ $coupon->code }}</td>
                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400">
                        {{ $coupon->type === 'percent' ? $coupon->value . '% off' : '¥' . number_format($coupon->value / 100, 2) }}
                    </td>
                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $coupon->used_count }}{{ $coupon->max_uses ? ' / ' . $coupon->max_uses : '' }}</td>
                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $coupon->expires_at?->format('Y-m-d') ?? '不限' }}</td>
                    <td class="px-4 py-2 text-right space-x-2">
                        <button wire:click="edit({{ $coupon->id }})" class="text-xs text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100">编辑</button>
                        <button wire:click="delete({{ $coupon->id }})" wire:confirm="确定删除这张优惠券？" class="text-xs text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300">删除</button>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-gray-400 dark:text-gray-500">还没有任何优惠券</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{ $coupons->links() }}
</div>
