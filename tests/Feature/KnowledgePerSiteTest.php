<?php

namespace Tests\Feature;

use App\Models\KnowledgeArticle;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Knowledge articles are shared platform-wide on purpose — an agent gets usable
 * docs without writing any — but hardcoding the platform's name in them tells
 * every 分站 customer whose service they're really buying.
 */
class KnowledgePerSiteTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenant()) {
            tenancy()->end();
        }
        foreach (Tenant::all() as $t) {
            $t->delete();
        }
        parent::tearDown();
    }

    public function test_site_name_token_resolves_to_the_site_being_viewed(): void
    {
        Setting::set('site_name', '平台官方');

        $article = KnowledgeArticle::create([
            'title' => '欢迎使用 {{site_name}}',
            'content' => '本文带你上手 {{site_name}}，祝使用愉快。',
            'category' => '入门', 'show' => true, 'sort' => 1,
        ]);

        // 主站 sees the platform's name.
        $this->assertSame('欢迎使用 平台官方', $article->renderedTitle());
        $this->assertStringContainsString('上手 平台官方', $article->renderedContent());

        $t = Tenant::create(['id' => 'kbshop']);
        $t->run(function () use ($article) {
            Setting::set('site_name', '代理小铺');

            $fresh = KnowledgeArticle::find($article->id);
            $this->assertSame('欢迎使用 代理小铺', $fresh->renderedTitle());
            $this->assertStringContainsString('上手 代理小铺', $fresh->renderedContent());
            $this->assertStringNotContainsString('平台官方', $fresh->renderedContent());
        });
    }

    public function test_the_article_is_shown_on_a_subdomain_with_that_shops_name(): void
    {
        Setting::set('site_name', '平台官方');
        KnowledgeArticle::create([
            'title' => '上手指南',
            'content' => '欢迎使用 {{site_name}}。',
            'category' => '入门', 'show' => true, 'sort' => 1,
        ]);

        $t = Tenant::create(['id' => 'kbsub']);
        $t->domains()->create(['domain' => 'kbsub']);
        $t->run(fn () => Setting::set('site_name', '代理小铺'));

        $user = $t->run(fn () => User::create([
            'name' => 'c', 'email' => 'c@example.com', 'password' => 'x',
        ]));

        $this->actingAs($user)->get('http://kbsub.example.test/knowledge')
            ->assertOk()
            ->assertSee('代理小铺')
            ->assertDontSee('平台官方');
    }

    public function test_text_without_the_token_is_left_alone(): void
    {
        $article = KnowledgeArticle::create([
            'title' => '连不上怎么办',
            'content' => '按这几步排查即可。',
            'category' => '故障', 'show' => true, 'sort' => 1,
        ]);

        $this->assertSame('连不上怎么办', $article->renderedTitle());
        $this->assertSame('按这几步排查即可。', $article->renderedContent());
    }
}
