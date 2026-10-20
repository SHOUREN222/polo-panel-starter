<?php

namespace Tests\Feature;

use App\Models\Notice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NoticesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_notice(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        \Livewire\Livewire::actingAs($admin)->test('admin.notices')
            ->call('create')
            ->set('title', '维护通知')
            ->set('content', '本周六凌晨 2 点进行例行维护')
            ->call('save');

        $this->assertDatabaseHas('notices', ['title' => '维护通知', 'show' => true]);
    }

    public function test_customer_dashboard_only_shows_visible_notices(): void
    {
        Notice::create(['title' => '可见公告', 'content' => 'x', 'show' => true, 'sort' => 0]);
        Notice::create(['title' => '隐藏公告', 'content' => 'x', 'show' => false, 'sort' => 0]);

        $user = User::factory()->create();

        $this->actingAs($user)->get('/')
            ->assertSee('可见公告')
            ->assertDontSee('隐藏公告');
    }

    public function test_admin_can_delete_a_notice(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $notice = Notice::create(['title' => 'x', 'content' => 'x', 'show' => true]);

        \Livewire\Livewire::actingAs($admin)->test('admin.notices')
            ->call('delete', $notice->id);

        $this->assertDatabaseMissing('notices', ['id' => $notice->id]);
    }

    public function test_admin_can_toggle_notice_visibility_inline(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $notice = Notice::create(['title' => 'x', 'content' => 'x', 'show' => true]);

        \Livewire\Livewire::actingAs($admin)->test('admin.notices')
            ->call('toggleShow', $notice->id);
        $this->assertFalse($notice->fresh()->show);

        \Livewire\Livewire::actingAs($admin)->test('admin.notices')
            ->call('toggleShow', $notice->id);
        $this->assertTrue($notice->fresh()->show);
    }

    public function test_announcement_bar_appears_on_every_customer_page(): void
    {
        Notice::create(['title' => '全局公告', 'content' => '维护通知', 'show' => true]);

        $user = User::factory()->create();

        $this->actingAs($user)->get('/')->assertSee('全局公告');
        $this->actingAs($user)->get('/orders')->assertSee('全局公告');
        $this->actingAs($user)->get('/plans')->assertSee('全局公告');
    }

    public function test_announcement_bar_hides_disabled_notices(): void
    {
        Notice::create(['title' => '隐藏的横幅', 'content' => 'x', 'show' => false]);

        $user = User::factory()->create();

        $this->actingAs($user)->get('/orders')->assertDontSee('隐藏的横幅');
    }
}
