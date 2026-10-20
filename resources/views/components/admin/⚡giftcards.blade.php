<?php

use App\Models\GiftCard;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin', ['title' => '礼品卡管理'])] class extends Component
{
    use WithPagination;

    public bool $showForm = false;
    public string $amount = '';
    public int $quantity = 1;

    public string $successMessage = '';
    public string $error = '';

    public function create(): void
    {
        $this->amount = '';
        $this->quantity = 1;
        $this->showForm = true;
    }

    public function generate(): void
    {
        $this->error = '';
        $this->successMessage = '';

        $this->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $cents = (int) round(((float) $this->amount) * 100);

        // A 分站 can only issue what its 进货余额 can eventually deliver, or the
        // agent sells cards they cannot honour and the customers find out at
        // checkout. 主站 mints against nothing, so it is unlimited.
        if ($tenant = tenant()) {
            $wholesale = app(\App\Services\WholesaleService::class);
            $headroom = $wholesale->giftCardHeadroom($tenant);

            if ($cents * $this->quantity > $headroom) {
                $this->error = '进货余额不足，最多还能发 ¥'.number_format($headroom / 100, 2)
                    .' 的卡。先补进货余额，或删掉未核销的卡。';

                return;
            }
        }

        for ($i = 0; $i < $this->quantity; $i++) {
            GiftCard::create([
                'code' => strtoupper(Str::random(4) . '-' . Str::random(4) . '-' . Str::random(4)),
                'amount' => $cents,
            ]);
        }

        $this->successMessage = "已生成 {$this->quantity} 张礼品卡";
        $this->showForm = false;
    }

    public function delete(int $cardId): void
    {
        $this->error = '';
        $card = GiftCard::findOrFail($cardId);

        if ($card->redeemed) {
            $this->error = '已兑换的礼品卡不能删除';

            return;
        }

        $card->delete();
        $this->successMessage = '礼品卡已删除';
    }

    public function cancel(): void
    {
        $this->showForm = false;
    }

    public function with(): array
    {
        return [
            'cards' => GiftCard::with('redeemedBy')->orderByDesc('created_at')->paginate(20),
            'headroom' => ($t = tenant())
                ? app(\App\Services\WholesaleService::class)->giftCardHeadroom($t)
                : null,
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">礼品卡管理</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">生成充值卡密，客户在「我的订单」页面兑换后直接增加账户余额。</p>
            @if ($headroom !== null)
                {{-- Say the limit up front: finding out at "生成" that you can't
                     issue the cards you already promised a buyer is too late. --}}
                <p class="text-sm mt-1 {{ $headroom > 0 ? 'text-gray-500 dark:text-gray-400' : 'text-red-600 dark:text-red-400' }}">
                    还可发行 <span class="font-medium">¥{{ number_format($headroom / 100, 2) }}</span>
                    <span class="text-xs text-gray-400 dark:text-gray-500">（受进货余额限制，未核销的卡也占额度）</span>
                </p>
            @endif
        </div>
        @unless ($showForm)
            <button wire:click="create" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-3 py-1.5 shrink-0">批量生成</button>
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
            <h2 class="font-medium text-gray-900 dark:text-gray-100">批量生成礼品卡</h2>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">面额（元）</label>
                    <input type="text" wire:model="amount" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('amount') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">生成数量</label>
                    <input type="number" wire:model="quantity" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('quantity') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="flex gap-2 pt-2">
                <button wire:click="generate" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">生成</button>
                <button wire:click="cancel" class="text-sm border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-4 py-2">取消</button>
            </div>
        </div>
    @endif

    <table class="w-full text-sm bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl overflow-hidden">
        <thead class="bg-gray-50 dark:bg-gray-800/60 text-gray-500 dark:text-gray-400 text-xs">
            <tr>
                <th class="text-left px-4 py-2">状态</th>
                <th class="text-left px-4 py-2">卡密</th>
                <th class="text-left px-4 py-2">面额</th>
                <th class="text-left px-4 py-2">兑换人</th>
                <th class="text-left px-4 py-2"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($cards as $card)
                <tr class="border-t border-gray-100 dark:border-gray-800">
                    <td class="px-4 py-2">
                        @if ($card->redeemed)
                            <span class="text-xs bg-gray-100 dark:bg-gray-800 text-gray-500 rounded-full px-2 py-1">已兑换</span>
                        @else
                            <span class="text-xs bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400 rounded-full px-2 py-1">未使用</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-gray-700 dark:text-gray-300 font-mono">{{ $card->code }}</td>
                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400">¥{{ number_format($card->amount / 100, 2) }}</td>
                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $card->redeemedBy?->email ?? '-' }}</td>
                    <td class="px-4 py-2 text-right">
                        @unless ($card->redeemed)
                            <button wire:click="delete({{ $card->id }})" wire:confirm="确定删除这张礼品卡？" class="text-xs text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300">删除</button>
                        @endunless
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-4 py-8 text-center text-gray-400 dark:text-gray-500">还没有生成任何礼品卡</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{ $cards->links() }}
</div>
