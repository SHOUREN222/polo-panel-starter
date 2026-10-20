<?php

use App\Models\RoutingRule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin', ['title' => '路由管理'])] class extends Component
{
    use WithPagination;

    private const ACTION_LABELS = [
        RoutingRule::ACTION_DIRECT => '直连',
        RoutingRule::ACTION_PROXY => '代理',
        RoutingRule::ACTION_BLOCK => '阻断',
    ];

    private const SCOPE_LABELS = [
        RoutingRule::SCOPE_BOTH => '全部节点',
        RoutingRule::SCOPE_SHARED => '仅共享节点',
        RoutingRule::SCOPE_ISP => '仅静态ISP中转',
    ];

    public bool $showForm = false;
    public ?int $editingId = null;

    public string $name = '';
    public string $pattern = '';
    public string $action = 'block';
    public string $scope = 'both';
    public bool $enabled = true;
    public int $sort = 0;

    public string $successMessage = '';

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $ruleId): void
    {
        $rule = RoutingRule::findOrFail($ruleId);

        $this->editingId = $rule->id;
        $this->name = $rule->name;
        $this->pattern = $rule->pattern;
        $this->action = $rule->action;
        $this->scope = $rule->scope ?? 'both';
        $this->enabled = $rule->enabled;
        $this->sort = $rule->sort;

        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'pattern' => ['required', 'string', 'max:255'],
            'action' => ['required', 'in:direct,proxy,block'],
            'scope' => ['required', 'in:shared,isp,both'],
        ]);

        $data = [
            'name' => $this->name,
            'pattern' => $this->pattern,
            'action' => $this->action,
            'scope' => $this->scope,
            'enabled' => $this->enabled,
            'sort' => $this->sort,
        ];

        if ($this->editingId) {
            RoutingRule::findOrFail($this->editingId)->update($data);
            $this->successMessage = '路由规则已更新';
        } else {
            RoutingRule::create($data);
            $this->successMessage = '路由规则已创建';
        }

        $this->showForm = false;
        $this->resetForm();
        $this->pushToNodes();
    }

    public function delete(int $ruleId): void
    {
        RoutingRule::findOrFail($ruleId)->delete();
        $this->successMessage = '路由规则已删除';
        $this->pushToNodes();
    }

    /**
     * Shared (V2bX) nodes pull routing rules automatically on their next poll
     * (~60s); the static-IP relay is push-based, so re-sync it now so the new
     * rules take effect immediately there too.
     */
    private function pushToNodes(): void
    {
        try {
            app(\App\Services\NodeRelayService::class)->sync();
        } catch (\Throwable $e) {
            // relay may be unconfigured / unreachable — rules still saved & live
            // on shared nodes via their pull; don't block the admin action.
        }
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    public function actionLabel(string $action): string
    {
        return self::ACTION_LABELS[$action] ?? $action;
    }

    public function scopeLabel(string $scope): string
    {
        return self::SCOPE_LABELS[$scope] ?? $scope;
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->pattern = '';
        $this->action = 'block';
        $this->scope = 'both';
        $this->enabled = true;
        $this->sort = 0;
    }

    public function with(): array
    {
        return [
            'rules' => RoutingRule::orderBy('sort')->orderBy('id')->paginate(20),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">路由管理</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">按域名/IP 段匹配走直连或阻断，规则会下发到节点真实生效。共享节点约 1 分钟内自动拉取，静态中转保存后立即推送。匹配写法：域名如 <code class="text-xs bg-gray-100 dark:bg-gray-800 rounded px-1">*.youtube.com</code>、IP 段如 <code class="text-xs bg-gray-100 dark:bg-gray-800 rounded px-1">10.0.0.0/8</code>。「代理」动作在中转节点上等同默认（无独立出口），主要用「阻断/直连」。</p>
        </div>
        @unless ($showForm)
            <button wire:click="create" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-3 py-1.5 shrink-0">新建规则</button>
        @endunless
    </div>

    @if ($successMessage)
        <div class="text-sm text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-500/10 border border-green-100 dark:border-green-500/20 rounded-lg p-3">{{ $successMessage }}</div>
    @endif

    @if ($showForm)
        <div class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 space-y-4">
            <h2 class="font-medium text-gray-900 dark:text-gray-100">{{ $editingId ? '编辑规则' : '新建规则' }}</h2>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">规则名称</label>
                    <input type="text" wire:model="name" placeholder="例如：屏蔽 BT 下载" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('name') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">匹配规则</label>
                    <input type="text" wire:model="pattern" placeholder="例如：*.example.com 或 10.0.0.0/8" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('pattern') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">动作</label>
                    <select wire:model="action" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        <option value="block">阻断</option>
                        <option value="direct">直连</option>
                        <option value="proxy">代理</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">作用范围</label>
                    <select wire:model="scope" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                        <option value="both">全部节点</option>
                        <option value="shared">仅共享节点</option>
                        <option value="isp">仅静态ISP中转</option>
                    </select>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">如"去广告"建议选「仅共享节点」，避免影响静态ISP注册客户的纯净度。</p>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">排序</label>
                    <input type="number" wire:model="sort" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model="enabled">
                启用
            </label>

            <div class="flex gap-2 pt-2">
                <button wire:click="save" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">保存</button>
                <button wire:click="cancel" class="text-sm border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-4 py-2">取消</button>
            </div>
        </div>
    @endif

    <table class="w-full text-sm bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl overflow-hidden">
        <thead class="bg-gray-50 dark:bg-gray-800/60 text-gray-500 dark:text-gray-400 text-xs">
            <tr>
                <th class="text-left px-4 py-2">启用</th>
                <th class="text-left px-4 py-2">名称</th>
                <th class="text-left px-4 py-2">匹配规则</th>
                <th class="text-left px-4 py-2">动作</th>
                <th class="text-left px-4 py-2">作用范围</th>
                <th class="text-left px-4 py-2"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rules as $rule)
                <tr class="border-t border-gray-100 dark:border-gray-800">
                    <td class="px-4 py-2">
                        @if ($rule->enabled)
                            <span class="text-xs bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400 rounded-full px-2 py-1">已启用</span>
                        @else
                            <span class="text-xs bg-gray-100 dark:bg-gray-800 text-gray-500 rounded-full px-2 py-1">已禁用</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-gray-700 dark:text-gray-300">{{ $rule->name }}</td>
                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400 font-mono text-xs">{{ $rule->pattern }}</td>
                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $this->actionLabel($rule->action) }}</td>
                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $this->scopeLabel($rule->scope ?? 'both') }}</td>
                    <td class="px-4 py-2 text-right space-x-2">
                        <button wire:click="edit({{ $rule->id }})" class="text-xs text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100">编辑</button>
                        <button wire:click="delete({{ $rule->id }})" wire:confirm="确定删除这条规则？" class="text-xs text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300">删除</button>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-gray-400 dark:text-gray-500">还没有配置任何路由规则</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{ $rules->links() }}
</div>
