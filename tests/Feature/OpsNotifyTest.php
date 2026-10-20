<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpsNotifyTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifies_only_telegram_bound_admins(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        Setting::set('telegram_bot_token', 'TESTTOKEN');

        $boundAdmin = User::factory()->create(['is_admin' => true, 'telegram_id' => 123456]);
        User::factory()->create(['is_admin' => true, 'telegram_id' => null]);   // admin, not bound
        User::factory()->create(['is_admin' => false, 'telegram_id' => 999]);   // bound, not admin

        $this->artisan('ops:notify', ['message' => '✅ backup ok'])->assertExitCode(0);

        Http::assertSentCount(1);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/sendMessage')
            && (string) $req['chat_id'] === (string) $boundAdmin->telegram_id
            && $req['text'] === '✅ backup ok');
    }

    public function test_noop_when_no_admin_is_bound(): void
    {
        Http::fake();
        Setting::set('telegram_bot_token', 'TESTTOKEN');
        User::factory()->create(['is_admin' => true, 'telegram_id' => null]);

        $this->artisan('ops:notify', ['message' => 'x'])->assertExitCode(0);

        Http::assertNothingSent();
    }
}
