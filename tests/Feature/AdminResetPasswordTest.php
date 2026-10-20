<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 站长 needs a way to get a locked-out customer back in when the reset email
 * can't reach them (QQ/163 spam filtering is common here). The new password is
 * generated, not typed, and shown to the admin once to hand over.
 */
class AdminResetPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_resets_a_customer_password_and_is_shown_it_once(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);
        $customer = User::create(['name' => 'c', 'email' => 'c@example.com', 'password' => 'old-password']);

        $component = Livewire::actingAs($admin)->test('admin.users')
            ->call('resetPassword', $customer->id);

        $message = $component->get('successMessage');
        $this->assertStringContainsString('已重置 c@example.com 的密码', $message);

        // The generated password is surfaced to the admin …
        preg_match('/新密码：(\S+)/u', $message, $m);
        $this->assertNotEmpty($m[1] ?? '', '成功提示里应包含新密码');
        $newPassword = $m[1];

        // … and it genuinely works, while the old one no longer does.
        $customer->refresh();
        $this->assertTrue(Hash::check($newPassword, $customer->password));
        $this->assertFalse(Hash::check('old-password', $customer->password));
    }

    public function test_support_admin_cannot_reset_passwords(): void
    {
        $support = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPPORT]);
        $customer = User::create(['name' => 'c', 'email' => 'c@example.com', 'password' => 'old-password']);

        Livewire::actingAs($support)->test('admin.users')
            ->call('resetPassword', $customer->id)
            ->assertSet('error', fn ($e) => str_contains((string) $e, '客服无权'));

        $this->assertTrue(Hash::check('old-password', $customer->fresh()->password));
    }

    public function test_an_admin_account_password_cannot_be_reset_here(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);
        $other = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);
        $original = $other->password;

        Livewire::actingAs($admin)->test('admin.users')
            ->call('resetPassword', $other->id)
            ->assertSet('error', fn ($e) => str_contains((string) $e, '管理员账号'));

        $this->assertSame($original, $other->fresh()->password);
    }
}
