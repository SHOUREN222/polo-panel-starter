<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A real customer registered as "5027009@QQ.COM" and was then locked out of
 * every email-keyed flow: SQLite compares case-sensitively, so logging in,
 * resetting a password, or requesting a magic link with the lowercase form
 * matched nobody — and because those screens deliberately never reveal whether
 * an account exists, it looked like the mail was being lost.
 */
class EmailCaseInsensitiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_is_stored_lowercased_however_it_is_typed(): void
    {
        $user = User::create([
            'name' => '大写用户',
            'email' => '  Me@QQ.Com  ',
            'password' => 'secret123',
        ]);

        $this->assertSame('me@qq.com', $user->fresh()->email);
    }

    public function test_login_works_no_matter_how_the_email_is_capitalised(): void
    {
        User::create(['name' => 'x', 'email' => 'Me@QQ.com', 'password' => 'secret123']);

        foreach (['me@qq.com', 'ME@QQ.COM', 'Me@QQ.com'] as $typed) {
            auth()->logout();
            Livewire::test('auth.login')
                ->set('email', $typed)
                ->set('password', 'secret123')
                ->call('login');

            $this->assertTrue(auth()->check(), "用 {$typed} 应该能登录");
        }
    }

    public function test_magic_login_finds_the_user_regardless_of_case(): void
    {
        Notification::fake();
        $user = User::create(['name' => 'x', 'email' => 'Me@QQ.com', 'password' => 'secret123']);

        Livewire::test('auth.login-by-email')
            ->set('email', 'ME@QQ.COM')
            ->call('send');

        Notification::assertSentTo($user, \App\Notifications\MagicLoginLink::class);
    }

    public function test_password_reset_finds_the_user_regardless_of_case(): void
    {
        Notification::fake();
        $user = User::create(['name' => 'x', 'email' => 'Me@QQ.com', 'password' => 'secret123']);

        Livewire::test('auth.forgot-password')
            ->set('email', 'me@qq.com')
            ->call('sendResetLink');

        Notification::assertSentTo($user, \App\Notifications\QueuedResetPassword::class);
    }

    public function test_migration_lowercases_pre_existing_rows(): void
    {
        // Bypass the model mutator to recreate a row as it existed before the fix.
        $user = User::create(['name' => 'x', 'email' => 'placeholder@qq.com', 'password' => 'secret123']);
        DB::table('users')->where('id', $user->id)->update(['email' => '5027009@QQ.COM']);
        $this->assertSame('5027009@QQ.COM', DB::table('users')->find($user->id)->email);

        (require database_path('migrations/2026_07_18_000000_lowercase_user_emails.php'))->up();

        $this->assertSame('5027009@qq.com', DB::table('users')->find($user->id)->email);
    }

    public function test_migration_leaves_a_colliding_row_alone(): void
    {
        // Both "a@qq.com" and "A@QQ.com" exist — lowercasing the second would
        // violate the unique index, so it must be skipped, not crash.
        $keep = User::create(['name' => 'a', 'email' => 'dup@qq.com', 'password' => 'secret123']);
        $other = User::create(['name' => 'b', 'email' => 'other@qq.com', 'password' => 'secret123']);
        DB::table('users')->where('id', $other->id)->update(['email' => 'DUP@QQ.COM']);

        (require database_path('migrations/2026_07_18_000000_lowercase_user_emails.php'))->up();

        $this->assertSame('dup@qq.com', DB::table('users')->find($keep->id)->email);
        $this->assertSame('DUP@QQ.COM', DB::table('users')->find($other->id)->email); // untouched
    }
}
