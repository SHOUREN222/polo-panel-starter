<?php

use App\Models\Setting;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.admin', ['title' => '主题配置'])] class extends Component
{
    public string $primaryColor = '#0d9488';
    public string $fontSize = 'normal';

    public string $successMessage = '';

    public function mount(): void
    {
        $this->primaryColor = Setting::get('theme_primary_color', '#0d9488');
        $this->fontSize = Setting::get('theme_font_size', 'normal');
    }

    public const DEFAULT_COLOR = '#0d9488';
    public const DEFAULT_FONT_SIZE = 'normal';

    public function save(): void
    {
        $this->validate([
            'primaryColor' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'fontSize' => ['required', 'in:small,normal,large'],
        ]);

        Setting::set('theme_primary_color', $this->primaryColor);
        Setting::set('theme_font_size', $this->fontSize);

        $this->successMessage = '主题已保存，前台页面刷新后生效';
    }

    /**
     * Restore the built-in defaults into the form fields (not persisted until
     * 保存 is clicked, so an accidental reset can still be abandoned).
     */
    public function resetDefaults(): void
    {
        $this->primaryColor = self::DEFAULT_COLOR;
        $this->fontSize = self::DEFAULT_FONT_SIZE;
        $this->successMessage = '';
    }
};
?>

<div class="space-y-6">
    <div>
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">主题配置</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">主题色、字体大小等前台外观设置，保存并刷新前台后生效。</p>
    </div>

    @if ($successMessage)
        <div class="text-sm text-green-700 dark:text-green-400 bg-green-50 dark:bg-green-500/10 border border-green-100 dark:border-green-500/20 rounded-lg p-3">{{ $successMessage }}</div>
    @endif

    <div
        x-data="{
            color: @entangle('primaryColor'),
            size: @entangle('fontSize'),
            get previewFontSize() { return this.size === 'small' ? '14px' : (this.size === 'large' ? '18px' : '16px'); },
        }"
        class="border border-gray-200 dark:border-gray-800 rounded-xl p-5 bg-white dark:bg-gray-900 space-y-5 max-w-xl"
    >
        <div>
            <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">主题色</label>
            <div class="flex items-center gap-3">
                <input type="color" x-model="color" class="w-12 h-9 border border-gray-300 dark:border-gray-700 rounded-lg bg-transparent">
                <input type="text" x-model="color" class="w-32 border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm font-mono">
            </div>
            @error('primaryColor') <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p> @enderror
            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">应用于客户端导航高亮、聚合订阅卡片等主品牌色元素。</p>
        </div>

        <div>
            <label class="block text-sm text-gray-600 dark:text-gray-400 mb-1">字体大小</label>
            <select x-model="size" class="w-full border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 rounded-lg px-3 py-2 text-sm">
                <option value="small">小</option>
                <option value="normal">标准</option>
                <option value="large">大</option>
            </select>
        </div>

        {{-- 实时预览：纯前端渲染，不用保存即可看到导航高亮/按钮/字号的效果 --}}
        <div>
            <label class="block text-sm text-gray-600 dark:text-gray-400 mb-2">效果预览</label>
            <div class="border border-gray-200 dark:border-gray-800 rounded-lg p-4 bg-gray-50 dark:bg-gray-950 space-y-3" :style="{ fontSize: previewFontSize }">
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center gap-2 px-3 py-2 rounded-lg font-medium" :style="{ color: color, backgroundColor: color + '1a' }">
                        <span class="w-2 h-2 rounded-full" :style="{ backgroundColor: color }"></span>
                        导航高亮
                    </span>
                    <span class="px-3 py-2 rounded-lg text-white text-sm font-medium" :style="{ backgroundColor: color }">主按钮</span>
                </div>
                <p class="text-gray-600 dark:text-gray-300">这段示例文字会随字体大小变化，链接 <a href="#" @click.prevent :style="{ color: color }" class="underline">像这样</a> 使用主题色。</p>
            </div>
        </div>

        <div class="pt-2 flex items-center gap-2">
            <button wire:click="save" class="text-sm bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 rounded-lg px-4 py-2">保存</button>
            <button wire:click="resetDefaults" type="button" class="text-sm text-gray-600 dark:text-gray-400 border border-gray-300 dark:border-gray-700 rounded-lg px-4 py-2 hover:bg-gray-50 dark:hover:bg-gray-800">恢复默认</button>
        </div>
    </div>
</div>
