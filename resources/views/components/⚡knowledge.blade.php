<?php

use App\Models\KnowledgeArticle;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app', ['title' => '使用文档'])] class extends Component
{
    public string $search = '';
    public string $category = '';

    public function clearSearch(): void
    {
        $this->search = '';
    }

    public function selectCategory(string $encodedCategory): void
    {
        $category = rawurldecode($encodedCategory);
        $available = KnowledgeArticle::where('show', true)
            ->get(['category'])
            ->map(fn (KnowledgeArticle $article) => $article->category ?: '常见问题');

        $this->category = $category !== '' && $available->contains($category) ? $category : '';
    }

    public function with(): array
    {
        $visible = KnowledgeArticle::where('show', true);
        $categories = (clone $visible)
            ->orderBy('sort')
            ->orderByDesc('created_at')
            ->get(['category'])
            ->map(fn (KnowledgeArticle $article) => $article->category ?: '常见问题')
            ->unique()
            ->values();

        $query = (clone $visible)->orderBy('sort')->orderByDesc('created_at');

        if ($this->category !== '') {
            $query->where(function ($q) {
                $q->where('category', $this->category);

                if ($this->category === '常见问题') {
                    $q->orWhereNull('category')->orWhere('category', '');
                }
            });
        }

        if (($search = trim($this->search)) !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('content', 'like', "%{$search}%");
            });
        }

        $matches = $query->get();

        return [
            'articles' => $matches->groupBy(fn (KnowledgeArticle $article) => $article->category ?: '常见问题'),
            'articleCount' => $matches->count(),
            'categories' => $categories,
        ];
    }
};
?>

<div class="space-y-6">
    <style>
        .kb-md { overflow-wrap: anywhere; }
        .kb-md > *:first-child { margin-top: 0; }
        .kb-md > *:last-child { margin-bottom: 0; }
        .kb-md p { margin: 0.5rem 0; line-height: 1.7; }
        .kb-md h1, .kb-md h2, .kb-md h3, .kb-md h4 { font-weight: 600; margin: 1rem 0 0.5rem; line-height: 1.3; color: inherit; }
        .kb-md h1 { font-size: 1.15rem; }
        .kb-md h2 { font-size: 1.05rem; }
        .kb-md h3, .kb-md h4 { font-size: 1rem; }
        .kb-md ul { list-style: disc; padding-left: 1.4rem; margin: 0.5rem 0; }
        .kb-md ol { list-style: decimal; padding-left: 1.4rem; margin: 0.5rem 0; }
        .kb-md li { margin: 0.2rem 0; }
        .kb-md a { color: var(--brand); text-decoration: underline; }
        .kb-md strong { font-weight: 600; color: inherit; }
        .kb-md code { font-family: ui-monospace, monospace; font-size: 0.85em; background: rgba(127,127,127,0.15); padding: 0.1em 0.35em; border-radius: 0.3rem; }
        .kb-md pre { background: rgba(127,127,127,0.12); padding: 0.75rem 1rem; border-radius: 0.5rem; overflow-x: auto; margin: 0.75rem 0; }
        .kb-md pre code { background: none; padding: 0; }
        .kb-md blockquote { border-left: 3px solid rgba(127,127,127,0.3); padding-left: 0.85rem; margin: 0.75rem 0; opacity: 0.85; }
        .kb-md hr { border: 0; border-top: 1px solid rgba(127,127,127,0.25); margin: 1rem 0; }
        .kb-md img { max-width: 100%; border-radius: 0.5rem; }
        .kb-md table { display: block; width: max-content; max-width: 100%; overflow-x: auto; border-collapse: collapse; margin: 0.75rem 0; }
        .kb-md th, .kb-md td { border: 1px solid rgba(127,127,127,0.3); padding: 0.4rem 0.7rem; text-align: left; white-space: nowrap; }
    </style>

    <div>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">使用文档</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">查找上手教程、续费规则和故障处理方法。</p>
    </div>

    <div class="relative">
        <span class="absolute inset-y-0 left-3 flex items-center text-gray-400 pointer-events-none">
            @include('partials.icon', ['name' => 'search'])
        </span>
        <input
            type="search"
            wire:model.live.debounce.400ms="search"
            placeholder="搜索标题或内容"
            aria-label="搜索使用文档"
            class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg pl-10 pr-10 py-2.5 text-sm"
        >
        @if ($search !== '')
            <button
                type="button"
                wire:click="clearSearch"
                aria-label="清空搜索"
                title="清空搜索"
                class="absolute inset-y-0 right-2 flex items-center px-2 text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
            >
                @include('partials.icon', ['name' => 'close'])
            </button>
        @endif
    </div>

    @if ($categories->isNotEmpty())
        <div class="border-b border-gray-200 dark:border-gray-800 overflow-x-auto" aria-label="文档分类">
            <div class="flex min-w-max gap-6">
                <button
                    type="button"
                    wire:click="selectCategory('')"
                    class="pb-2.5 text-sm whitespace-nowrap border-b-2 {{ $category === '' ? 'border-gray-900 dark:border-gray-100 text-gray-900 dark:text-gray-100 font-medium' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200' }}"
                >全部</button>
                @foreach ($categories as $categoryName)
                    <button
                        type="button"
                        wire:click="selectCategory('{{ rawurlencode($categoryName) }}')"
                        class="pb-2.5 text-sm whitespace-nowrap border-b-2 {{ $category === $categoryName ? 'border-gray-900 dark:border-gray-100 text-gray-900 dark:text-gray-100 font-medium' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200' }}"
                    >{{ $categoryName }}</button>
                @endforeach
            </div>
        </div>
    @endif

    <div class="flex items-center justify-between gap-3 text-xs text-gray-500 dark:text-gray-400">
        <span>找到 {{ $articleCount }} 篇文档</span>
        @if ($category !== '')
            <span class="truncate">当前分类：{{ $category }}</span>
        @endif
    </div>

    @if ($articleCount > 0)
        <div class="space-y-5">
            @foreach ($articles as $groupCategory => $group)
                <section class="border border-gray-200 dark:border-gray-800 rounded-lg bg-white dark:bg-gray-900 divide-y divide-gray-100 dark:divide-gray-800">
                    <h2 class="text-sm font-medium text-gray-500 dark:text-gray-400 px-5 py-3">{{ $groupCategory }}</h2>
                    @foreach ($group as $article)
                        <details
                            wire:key="knowledge-{{ md5($category.'|'.$search.'|'.$article->id) }}"
                            class="group"
                            @if ($loop->parent->first && $loop->first) open @endif
                        >
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 hover:bg-gray-50 dark:hover:bg-gray-800/60 [&::-webkit-details-marker]:hidden">
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium text-gray-900 dark:text-gray-100">{{ $article->renderedTitle() }}</span>
                                    <span class="block text-xs text-gray-400 dark:text-gray-500 mt-1">更新于 {{ $article->updated_at->format('Y-m-d') }}</span>
                                </span>
                                <span class="shrink-0 text-gray-400 transition-transform group-open:rotate-180">
                                    @include('partials.icon', ['name' => 'chevron-down'])
                                </span>
                            </summary>
                            <div class="kb-md px-5 pb-5 text-sm text-gray-600 dark:text-gray-300">{!! Str::markdown($article->renderedContent(), ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>
                        </details>
                    @endforeach
                </section>
            @endforeach
        </div>
    @else
        <div class="border border-dashed border-gray-300 dark:border-gray-700 rounded-lg px-5 py-10 text-center">
            <p class="text-sm text-gray-500 dark:text-gray-400">没有找到匹配的文档。</p>
            @if ($search !== '')
                <button type="button" wire:click="clearSearch" class="mt-2 text-sm text-teal-600 dark:text-teal-400 hover:underline">清空搜索</button>
            @endif
        </div>
    @endif
</div>
