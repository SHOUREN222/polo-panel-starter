<?php

use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin', ['title' => '权限组管理'])] class extends Component
{
    use WithPagination;

    public bool $showForm = false;
    public ?int $editingId = null;
    public string $name = '';

    public string $successMessage = '';
    public string $error = '';

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $groupId): void
    {
        $group = ServerGroup::findOrFail($groupId);
        $this->editingId = $group->id;
        $this->name = $group->name;
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate(['name' => ['required', 'string', 'max:255']]);

        if ($this->editingId) {
            ServerGroup::findOrFail($this->editingId)->update(['name' => $this->name]);
            $this->successMessage = '权限组已更新';
        } else {
            ServerGroup::create(['name' => $this->name]);
            $this->successMessage = '权限组已创建';
        }

        $this->showForm = false;
        $this->resetForm();
    }

    public function delete(int $groupId): void
    {
        $this->error = '';
        $this->successMessage = '';

        $inUse = Server::where('group_id', $groupId)->exists() || Plan::where('group_id', $groupId)->exists();
        if ($inUse) {
            $this->error = '该权限组下还有节点或套餐在使用，无法删除';

            return;
        }

        ServerGroup::findOrFail($groupId)->delete();
        $this->successMessage = '权限组已删除';
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
    }

    public function with(): array
    {
        $groups = ServerGroup::orderBy('id')->paginate(20);

        return [
            'groups' => $groups,
            'serverCounts' => Server::selectRaw('group_id, count(*) as c')->groupBy('group_id')->pluck('c', 'group_id'),
            'planCounts' => Plan::selectRaw('group_id, count(*) as c')->whereNotNull('group_id')->groupBy('group_id')->pluck('c', 'group_id'),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">权限组管理</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">控制哪些套餐能看到哪些共享节点——套餐和服务器都归属某个权限组，同组才能互相匹配。</p>
        </div>
        @unless ($showForm)
            <button wire:click="create" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-3 py-1.5 shrink-0">新建权限组</button>
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
            <h2 class="font-medium text-gray-900 dark:text-gray-100">{{ $editingId ? '编辑权限组' : '新建权限组' }}</h2>
            <div>
                <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">名称</label>
                <input type="text" wire:model="name" class="w-full max-w-sm border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                @error('name') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="flex gap-2 pt-2">
                <button wire:click="save" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">保存</button>
                <button wire:click="cancel" class="text-sm border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-lg px-4 py-2">取消</button>
            </div>
        </div>
    @endif

    <table class="w-full text-sm bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-xl overflow-hidden">
        <thead class="bg-gray-50 dark:bg-gray-800/60 text-gray-500 dark:text-gray-400 text-xs">
            <tr>
                <th class="text-left px-4 py-2">ID</th>
                <th class="text-left px-4 py-2">名称</th>
                <th class="text-left px-4 py-2">节点数</th>
                <th class="text-left px-4 py-2">套餐数</th>
                <th class="text-left px-4 py-2"></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($groups as $group)
                <tr class="border-t border-gray-100 dark:border-gray-800">
                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $group->id }}</td>
                    <td class="px-4 py-2 text-gray-700 dark:text-gray-300">{{ $group->name }}</td>
                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $serverCounts[$group->id] ?? 0 }}</td>
                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $planCounts[$group->id] ?? 0 }}</td>
                    <td class="px-4 py-2 text-right space-x-2">
                        <button wire:click="edit({{ $group->id }})" class="text-xs text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100">编辑</button>
                        <button wire:click="delete({{ $group->id }})" wire:confirm="确定删除这个权限组？" class="text-xs text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300">删除</button>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{ $groups->links() }}
</div>
