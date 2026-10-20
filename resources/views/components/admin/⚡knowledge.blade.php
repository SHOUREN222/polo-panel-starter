<?php

use App\Models\KnowledgeArticle;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin', ['title' => '知识库管理'])] class extends Component
{
    use WithPagination;

    public bool $showForm = false;
    public ?int $editingId = null;

    public string $title = '';
    public string $content = '';
    public string $category = '';
    public bool $show = true;
    public int $sort = 0;

    public string $successMessage = '';

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $articleId): void
    {
        $article = KnowledgeArticle::findOrFail($articleId);

        $this->editingId = $article->id;
        $this->title = $article->title;
        $this->content = $article->content;
        $this->category = $article->category ?? '';
        $this->show = $article->show;
        $this->sort = $article->sort;

        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'category' => ['nullable', 'string', 'max:100'],
            'sort' => ['integer'],
        ]);

        $data = [
            'title' => $this->title,
            'content' => $this->content,
            'category' => $this->category ?: null,
            'show' => $this->show,
            'sort' => $this->sort,
        ];

        if ($this->editingId) {
            KnowledgeArticle::findOrFail($this->editingId)->update($data);
            $this->successMessage = '文章已更新';
        } else {
            KnowledgeArticle::create($data);
            $this->successMessage = '文章已创建';
        }

        $this->showForm = false;
        $this->resetForm();
    }

    public function toggleShow(int $articleId): void
    {
        $article = KnowledgeArticle::findOrFail($articleId);
        $article->update(['show' => ! $article->show]);
        $this->successMessage = $article->show ? '文章已显示' : '文章已隐藏';
    }

    public function delete(int $articleId): void
    {
        KnowledgeArticle::findOrFail($articleId)->delete();
        $this->successMessage = '文章已删除';
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
        $this->category = '';
        $this->show = true;
        $this->sort = 0;
    }

    public function with(): array
    {
        return [
            'articles' => KnowledgeArticle::orderBy('sort')->orderByDesc('created_at')->paginate(20),
        ];
    }
};
?>

<div class="space-y-6">
    <style>
        .prose-preview { overflow-wrap: anywhere; }
        .prose-preview > *:first-child { margin-top: 0; }
        .prose-preview > *:last-child { margin-bottom: 0; }
        .prose-preview p { margin: 0.5rem 0; line-height: 1.7; }
        .prose-preview h1, .prose-preview h2, .prose-preview h3 { font-weight: 600; margin: 1rem 0 0.5rem; line-height: 1.3; }
        .prose-preview h1 { font-size: 1.15rem; }
        .prose-preview h2 { font-size: 1.05rem; }
        .prose-preview ul { list-style: disc; padding-left: 1.4rem; margin: 0.5rem 0; }
        .prose-preview ol { list-style: decimal; padding-left: 1.4rem; margin: 0.5rem 0; }
        .prose-preview blockquote { border-left: 3px solid rgba(127,127,127,0.3); padding-left: 0.85rem; margin: 0.75rem 0; }
        .prose-preview table { display: block; width: max-content; max-width: 100%; overflow-x: auto; border-collapse: collapse; margin: 0.75rem 0; }
        .prose-preview th, .prose-preview td { border: 1px solid rgba(127,127,127,0.3); padding: 0.4rem 0.7rem; white-space: nowrap; }
    </style>

    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">知识库管理</h1>
        @unless ($showForm)
            <button wire:click="create" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-3 py-1.5">添加文章</button>
        @endunless
    </div>

    @if ($successMessage)
        <div class="text-sm text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-500/10 border border-green-100 dark:border-green-500/20 rounded-lg p-3">{{ $successMessage }}</div>
    @endif

    @if ($showForm)
        <div x-data="{ editorMode: 'edit' }" class="border border-gray-200 dark:border-gray-800 rounded-lg p-4 sm:p-5 bg-white dark:bg-gray-900 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="font-medium text-gray-900 dark:text-gray-100">{{ $editingId ? '编辑文章' : '添加文章' }}</h2>
                <div class="inline-flex rounded-lg border border-gray-300 dark:border-gray-700 p-0.5" role="group" aria-label="内容编辑模式">
                    <button
                        type="button"
                        @click="editorMode = 'edit'"
                        :class="editorMode === 'edit' ? 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900' : 'text-gray-600 dark:text-gray-300'"
                        class="rounded-md px-3 py-1.5 text-xs"
                    >编辑</button>
                    <button
                        type="button"
                        @click="editorMode = 'preview'"
                        :class="editorMode === 'preview' ? 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900' : 'text-gray-600 dark:text-gray-300'"
                        class="rounded-md px-3 py-1.5 text-xs"
                    >预览</button>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">标题</label>
                    <input type="text" wire:model="title" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                    @error('title') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">分类（可留空）</label>
                    <input type="text" wire:model="category" placeholder="例如：新手指南" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                </div>
            </div>

            <div>
                <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">内容</label>
                <div x-show="editorMode === 'edit'">
                    <textarea wire:model.live.debounce.300ms="content" rows="12" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono"></textarea>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                        知识库对所有分站共享。提到站名时请写
                        <code class="bg-gray-100 dark:bg-gray-800 px-1 rounded">@{{site_name}}</code>
                        ——它会自动替换成客户当前所在站点的名字；写死平台名会让代理的客户看到你的品牌。
                    </p>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">支持 Markdown 语法：<code># 标题</code>、<code>**加粗**</code>、<code>- 列表</code>、<code>[链接](https://…)</code> 等。原始 HTML 会被转义，不会执行。</p>
                </div>
                <div x-show="editorMode === 'preview'" x-cloak class="min-h-72 border border-gray-200 dark:border-gray-700 rounded-lg bg-gray-50 dark:bg-gray-800/50 p-4 text-sm text-gray-700 dark:text-gray-300 prose-preview">
                    @if (trim($content) !== '')
                        {!! Str::markdown($content, ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}
                    @else
                        <p class="text-gray-400 dark:text-gray-500">暂无可预览内容</p>
                    @endif
                </div>
                @error('content') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="flex flex-wrap items-center gap-6">
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

    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900">
        <table class="w-full min-w-[680px] text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800/60 text-gray-500 dark:text-gray-400 text-xs">
                <tr>
                    <th class="text-left px-4 py-2">显示状态</th>
                    <th class="text-left px-4 py-2">排序</th>
                    <th class="text-left px-4 py-2">标题</th>
                    <th class="text-left px-4 py-2">分类</th>
                    <th class="text-left px-4 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($articles as $article)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="px-4 py-2">
                            @if ($article->show)
                                <span class="text-xs bg-green-50 dark:bg-green-500/10 text-green-700 dark:text-green-400 rounded-full px-2 py-1">显示中</span>
                            @else
                                <span class="text-xs bg-gray-100 dark:bg-gray-800 text-gray-500 rounded-full px-2 py-1">已隐藏</span>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $article->sort }}</td>
                        <td class="px-4 py-2 text-gray-700 dark:text-gray-300">{{ $article->title }}</td>
                        <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $article->category ?? '-' }}</td>
                        <td class="px-4 py-2 text-right space-x-2">
                            <button wire:click="toggleShow({{ $article->id }})" class="text-xs text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100">{{ $article->show ? '隐藏' : '显示' }}</button>
                            <button wire:click="edit({{ $article->id }})" class="text-xs text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100">编辑</button>
                            <button wire:click="delete({{ $article->id }})" wire:confirm="确定删除这篇文章？" class="text-xs text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300">删除</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-gray-400 dark:text-gray-500">还没有任何文章</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $articles->links() }}
</div>
