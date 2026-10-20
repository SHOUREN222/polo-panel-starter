<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_owner_administrator_without_demo_data(): void
    {
        $this->artisan('app:create-admin', ['email' => 'owner@example.com', '--name' => 'Owner'])
            ->expectsQuestion('Password (at least 12 characters)', 'a-strong-password')
            ->expectsQuestion('Confirm password', 'a-strong-password')
            ->assertSuccessful();

        $user = User::where('email', 'owner@example.com')->firstOrFail();

        $this->assertTrue($user->is_admin);
        $this->assertTrue($user->is_owner);
        $this->assertSame(User::ROLE_SUPER, $user->admin_role);
    }
}
