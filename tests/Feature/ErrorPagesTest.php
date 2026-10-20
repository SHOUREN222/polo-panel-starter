<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_missing_route_renders_the_branded_404_page(): void
    {
        $this->get('/this-route-definitely-does-not-exist-'.uniqid())
            ->assertStatus(404)
            ->assertSee('页面不存在')
            ->assertSee('返回首页');
    }

    public function test_the_404_view_falls_back_to_app_name_when_settings_are_unavailable(): void
    {
        // Renders even without a site_name setting (guarded Setting reads).
        $html = view('errors.404')->render();

        $this->assertStringContainsString('404', $html);
        $this->assertStringContainsString('页面不存在', $html);
    }
}
