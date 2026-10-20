<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_theme_settings(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        \Livewire\Livewire::actingAs($admin)->test('admin.theme')
            ->set('primaryColor', '#ff0000')
            ->set('fontSize', 'large')
            ->call('save');

        $this->assertSame('#ff0000', Setting::get('theme_primary_color'));
        $this->assertSame('large', Setting::get('theme_font_size'));
    }

    public function test_theme_color_rejects_invalid_hex(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        \Livewire\Livewire::actingAs($admin)->test('admin.theme')
            ->set('primaryColor', 'not-a-color')
            ->call('save')
            ->assertHasErrors(['primaryColor']);
    }

    public function test_dashboard_reflects_configured_theme_color(): void
    {
        Setting::set('theme_primary_color', '#ff00aa');
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')
            ->assertSee('--brand: #ff00aa', false);
    }

    public function test_dashboard_reflects_configured_font_size(): void
    {
        Setting::set('theme_font_size', 'large');
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')
            ->assertSee('font-size: 18px', false);
    }

    public function test_reset_defaults_restores_form_fields_without_persisting(): void
    {
        Setting::set('theme_primary_color', '#123456');
        Setting::set('theme_font_size', 'large');
        $admin = User::factory()->create(['is_admin' => true]);

        \Livewire\Livewire::actingAs($admin)->test('admin.theme')
            ->call('resetDefaults')
            ->assertSet('primaryColor', '#0d9488')
            ->assertSet('fontSize', 'normal');

        // Not persisted until 保存 is clicked — stored settings are unchanged.
        $this->assertSame('#123456', Setting::get('theme_primary_color'));
        $this->assertSame('large', Setting::get('theme_font_size'));
    }
}
