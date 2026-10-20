<?php

namespace Tests\Feature;

use App\Models\KnowledgeArticle;
use App\Models\User;
use Database\Seeders\KnowledgeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class KnowledgeBaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_an_article(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test('admin.knowledge')
            ->call('create')
            ->set('title', '如何续费')
            ->set('content', '在续费订阅页面选择周期即可')
            ->set('category', '新手指南')
            ->call('save');

        $this->assertDatabaseHas('knowledge_articles', ['title' => '如何续费', 'category' => '新手指南']);
    }

    public function test_customer_knowledge_page_only_shows_visible_articles(): void
    {
        KnowledgeArticle::create(['title' => '可见文章', 'content' => 'x', 'show' => true]);
        KnowledgeArticle::create(['title' => '隐藏文章', 'content' => 'x', 'show' => false]);

        $user = User::factory()->create();

        $this->actingAs($user)->get('/knowledge')
            ->assertSee('可见文章')
            ->assertDontSee('隐藏文章');
    }

    public function test_customer_can_search_knowledge_articles(): void
    {
        KnowledgeArticle::create(['title' => '如何续费', 'content' => 'x', 'show' => true]);
        KnowledgeArticle::create(['title' => '如何购买', 'content' => 'y', 'show' => true]);

        $user = User::factory()->create();

        $component = Livewire::actingAs($user)->test('knowledge')
            ->set('search', '续费');

        $this->assertTrue($component->viewData('articles')->flatten()->contains('title', '如何续费'));
        $this->assertFalse($component->viewData('articles')->flatten()->contains('title', '如何购买'));
    }

    public function test_customer_can_filter_by_category_and_clear_search(): void
    {
        KnowledgeArticle::create(['title' => '客户端下载', 'content' => '导入方法', 'category' => '新手指南', 'show' => true]);
        KnowledgeArticle::create(['title' => '续费规则', 'content' => '续费方法', 'category' => '套餐与流量', 'show' => true]);

        $user = User::factory()->create();
        $component = Livewire::actingAs($user)->test('knowledge')
            ->call('selectCategory', rawurlencode('套餐与流量'));

        $this->assertSame(1, $component->viewData('articleCount'));
        $this->assertTrue($component->viewData('articles')->flatten()->contains('title', '续费规则'));
        $this->assertFalse($component->viewData('articles')->flatten()->contains('title', '客户端下载'));

        $component->set('search', '没有结果')
            ->assertSet('search', '没有结果')
            ->call('clearSearch')
            ->assertSet('search', '');
    }

    public function test_customer_page_opens_the_first_article_by_default(): void
    {
        KnowledgeArticle::create(['title' => '第一篇', 'content' => '内容一', 'category' => '新手指南', 'show' => true, 'sort' => 10]);
        KnowledgeArticle::create(['title' => '第二篇', 'content' => '内容二', 'category' => '新手指南', 'show' => true, 'sort' => 20]);

        $user = User::factory()->create();
        $html = $this->actingAs($user)->get('/knowledge')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<details[^>]*\sopen(?:\s|>)/', $html);
        $this->assertStringContainsString('更新于', $html);
    }

    public function test_admin_can_toggle_article_visibility_inline(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $article = KnowledgeArticle::create(['title' => 'x', 'content' => 'x', 'show' => true]);

        Livewire::actingAs($admin)->test('admin.knowledge')
            ->call('toggleShow', $article->id);
        $this->assertFalse($article->fresh()->show);

        Livewire::actingAs($admin)->test('admin.knowledge')
            ->call('toggleShow', $article->id);
        $this->assertTrue($article->fresh()->show);
    }

    public function test_admin_markdown_preview_is_rendered_and_raw_html_is_escaped(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test('admin.knowledge')
            ->call('create')
            ->set('content', '**预览内容** <script>alert(1)</script>')
            ->assertSeeHtml('<strong>预览内容</strong>')
            ->assertDontSeeHtml('<script>alert(1)</script>')
            ->assertSeeHtml('&lt;script&gt;');
    }

    public function test_customer_page_renders_article_markdown(): void
    {
        KnowledgeArticle::create([
            'title' => 'Markdown 文章',
            'content' => "# 大标题\n\n- 第一项\n- 第二项\n\n**加粗**内容",
            'show' => true,
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)->get('/knowledge')
            ->assertSee('<h1>大标题</h1>', false)
            ->assertSee('<li>第一项</li>', false)
            ->assertSee('<strong>加粗</strong>', false);
    }

    public function test_customer_page_escapes_raw_html_in_articles(): void
    {
        KnowledgeArticle::create([
            'title' => 'XSS 测试',
            'content' => '正常内容 <script>alert(1)</script> 结束',
            'show' => true,
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)->get('/knowledge')
            // The script tag is escaped, never emitted as an executable element.
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;', false);
    }

    public function test_knowledge_seeder_preserves_existing_admin_edits_and_visibility(): void
    {
        $default = KnowledgeSeeder::articles()[0];
        KnowledgeArticle::create([
            'title' => $default['title'],
            'content' => '管理员修改后的内容',
            'category' => '自定义分类',
            'show' => true,
            'sort' => 999,
        ]);

        $this->seed(KnowledgeSeeder::class);
        $this->seed(KnowledgeSeeder::class);

        $article = KnowledgeArticle::where('title', $default['title'])->sole();
        $this->assertSame('管理员修改后的内容', $article->content);
        $this->assertSame('自定义分类', $article->category);
        $this->assertTrue($article->show);
        $this->assertSame(999, $article->sort);
        $this->assertSame(count(KnowledgeSeeder::articles()), KnowledgeArticle::count());
    }

    public function test_default_knowledge_articles_are_unique_and_match_current_workflows(): void
    {
        $articles = collect(KnowledgeSeeder::articles());
        $content = $articles->pluck('content')->implode("\n");

        $this->assertCount(10, $articles);
        $this->assertCount(10, $articles->pluck('title')->unique());
        $this->assertStringContainsString('流量用尽不等于线路到期', $content);
        $this->assertStringContainsString('到期前完成续费', $content);
        $this->assertStringContainsString('续费订阅', $content);
        $this->assertStringContainsString('兑换礼品卡', $content);
        $this->assertStringNotContainsString('使用文档 → 客户端下载', $content);
        $this->assertStringNotContainsString('主力故障自动切换', $content);
    }
}
