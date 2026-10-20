<?php

use App\Models\Notice;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin', ['title' => '公告管理'])] class extends Component
{
    use WithPagination;

    public bool $showForm = false;
    public ?int $editingId = null;

    public string $title = '';
    public string $content = '';
    public bool $show = true;
    public int $sort = 0;

    public string $successMessage = '';
    public string $error = '';

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $noticeId): void
    {
        $notice = Notice::findOrFail($noticeId);

        $this->editingId = $notice->id;
        $this->title = $notice->title;
        $this->content = $notice->content;
        $this->show = $notice->show;
        $this->sort = $notice->sort;

        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
        ]);

        $data = [
            'title' => $this->title,
            'content' => $this->content,
            'show' => $this->show,
            'sort' => $this->sort,
        ];

        if ($this->editingId) {
            Notice::findOrFail($this->editingId)->update($data);
            $this->successMessage = '公告已更新';
        } else {
            Notice::create($data);
            $this->successMessage = '公告已创建';
        }

        $this->showForm = false;
        $this->resetForm();
    }

    public function toggleShow(int $noticeId): void
    {
        $notice = Notice::findOrFail($noticeId);
        $notice->update(['show' => ! $notice->show]);
        $this->successMessage = $notice->show ? '公告已显示' : '公告已隐藏';
    }

    public function delete(int $noticeId): void
    {
        Notice::findOrFail($noticeId)->delete();
        $this->successMessage = '公告已删除';
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->title = '';
        $this->content = '';
        $this->show = true;
        $this->sort = 0;
    }

    public function with(): array
    {
        return [
            'notices' => Notice::orderBy('sort')->orderByDesc('created_at')->paginate(20),
        ];
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">公告管理</h1>
        @unless ($showForm)
            <button wire:click="create" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-3 py-1.5">添加公告</button>
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
            <h2 class="font-medium text-gray-900 dark:text-gray-100">{{ $editingId ? '编辑公告' : '添加公告' }}</h2>

            <div>
                <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">标题</label>
                <input type="text" wire:model="title" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                @error('title') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">内容</label>
                <textarea wire:model="content" rows="4" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm"></textarea>
                @error('content') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="flex items-center gap-6">
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">排序</label>
                    <input type="number" wire:model="sort" class="w-24 border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                </div>
                <label class="flex items-center gap-2 text-sm mt-5">
                    <input type="checkbox" wire:model="show">
                    显示（客户前台可见）
                </label>
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
                <th class="text-left px-4 py-2">显示状态</th>
                <th class="text-left px-4 py-2">排序</th>
                <th class="text-left px-4 py-2">标题</th>
                <th class="text-left px-4 py-2">创建时间</th>
                <th class="text-left px-4 py-2"></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($notices as $notice)
                <tr class="border-t border-gray-100 dark:border-gray-800">
                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $notice->id }}</td>
                    <td class="px-4 py-2">
                        @if ($notice->show)
                            <span class="text-xs bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400 rounded-full px-2 py-1">显示中</span>
                        @else
                            <span class="text-xs bg-gray-100 dark:bg-gray-800 text-gray-500 rounded-full px-2 py-1">已隐藏</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $notice->sort }}</td>
                    <td class="px-4 py-2 text-gray-700 dark:text-gray-300">{{ $notice->title }}</td>
                    <td class="px-4 py-2 text-gray-500 dark:text-gray-400 text-xs whitespace-nowrap">{{ $notice->created_at?->format('Y-m-d H:i') }}</td>
                    <td class="px-4 py-2 text-right space-x-2">
                        <button wire:click="toggleShow({{ $notice->id }})" class="text-xs text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100">{{ $notice->show ? '隐藏' : '显示' }}</button>
                        <button wire:click="edit({{ $notice->id }})" class="text-xs text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100">编辑</button>
                        <button wire:click="delete({{ $notice->id }})" wire:confirm="确定删除这条公告？" class="text-xs text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300">删除</button>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-gray-400 dark:text-gray-500">还没有任何公告</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    {{ $notices->links() }}
</div>
