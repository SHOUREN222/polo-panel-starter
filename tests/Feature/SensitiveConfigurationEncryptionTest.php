<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Server;
use App\Models\Setting;
use App\Models\Tenant;
use App\Support\EncryptedValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SensitiveConfigurationEncryptionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenant()) {
            tenancy()->end();
        }

        foreach (Tenant::all() as $tenant) {
            $tenant->delete();
        }

        parent::tearDown();
    }

    public function test_sensitive_settings_are_encrypted_but_normal_settings_remain_plaintext(): void
    {
        Setting::set('mail_password', 'smtp-secret-value');
        Setting::set('site_name', 'Visible site name');

        $rawSecret = DB::table('settings')->where('key', 'mail_password')->value('value');

        $this->assertTrue(EncryptedValue::isEncrypted($rawSecret));
        $this->assertStringNotContainsString('smtp-secret-value', $rawSecret);
        $this->assertSame('smtp-secret-value', Setting::get('mail_password'));
        $this->assertSame(
            'Visible site name',
            DB::table('settings')->where('key', 'site_name')->value('value'),
        );
    }

    public function test_payment_and_server_credentials_are_encrypted_inside_config_json(): void
    {
        $payment = Payment::create([
            'name' => 'BTCPay',
            'gateway' => Payment::GATEWAY_BTCPAY,
            'enable' => true,
            'config' => [
                'server_url' => 'https://pay.example.com',
                'api_key' => 'payment-api-secret',
                'webhook_secret' => 'payment-webhook-secret',
            ],
        ]);
        $server = Server::create([
            'name' => 'Reality node',
            'group_id' => 1,
            'host' => '192.0.2.10',
            'port' => 443,
            'protocol' => 'vless',
            'config' => [
                'public_key' => 'public-value',
                'private_key' => 'node-private-secret',
            ],
        ]);

        $rawPayment = (string) DB::table('payments')->where('id', $payment->id)->value('config');
        $rawServer = (string) DB::table('servers')->where('id', $server->id)->value('config');

        $this->assertStringContainsString('https://pay.example.com', $rawPayment);
        $this->assertStringNotContainsString('payment-api-secret', $rawPayment);
        $this->assertStringNotContainsString('payment-webhook-secret', $rawPayment);
        $this->assertSame('payment-api-secret', $payment->fresh()->config['api_key']);
        $this->assertStringContainsString('public-value', $rawServer);
        $this->assertStringNotContainsString('node-private-secret', $rawServer);
        $this->assertSame('node-private-secret', $server->fresh()->config['private_key']);
    }

    public function test_heartbeat_secret_is_encrypted_and_looked_up_by_hash(): void
    {
        $server = Server::create([
            'name' => 'Node',
            'group_id' => 1,
            'host' => '192.0.2.11',
            'port' => 443,
        ]);
        $secret = $server->heartbeat_secret;
        $raw = DB::table('servers')->where('id', $server->id)->first();

        $this->assertTrue(EncryptedValue::isEncrypted($raw->heartbeat_secret));
        $this->assertStringNotContainsString($secret, $raw->heartbeat_secret);
        $this->assertSame(hash('sha256', $secret), $raw->heartbeat_secret_hash);
        $this->assertSame($server->id, Server::findByHeartbeatSecret($secret)?->id);

        $this->postJson('/api/servers/heartbeat', ['secret' => $secret])->assertOk();
    }

    public function test_migration_command_repairs_every_legacy_heartbeat_secret(): void
    {
        $servers = collect(range(1, 3))->map(function (int $index) {
            $server = Server::create([
                'name' => 'Legacy node '.$index,
                'group_id' => 1,
                'host' => '192.0.2.'.(20 + $index),
                'port' => 443,
            ]);
            $secret = 'legacy-heartbeat-secret-'.$index;

            DB::table('servers')->where('id', $server->id)->update([
                'heartbeat_secret' => $secret,
                'heartbeat_secret_hash' => null,
            ]);

            return [$server->id, $secret];
        });

        Artisan::call('security:encrypt-sensitive-config');

        foreach ($servers as [$serverId, $secret]) {
            $raw = DB::table('servers')->where('id', $serverId)->first();

            $this->assertTrue(EncryptedValue::isEncrypted($raw->heartbeat_secret));
            $this->assertStringNotContainsString($secret, $raw->heartbeat_secret);
            $this->assertSame(hash('sha256', $secret), $raw->heartbeat_secret_hash);
            $this->assertSame($serverId, Server::findByHeartbeatSecret($secret)?->id);
        }

        $beforeSecondRun = DB::table('servers')->orderBy('id')->pluck('heartbeat_secret')->all();
        Artisan::call('security:encrypt-sensitive-config');
        $afterSecondRun = DB::table('servers')->orderBy('id')->pluck('heartbeat_secret')->all();

        $this->assertSame($beforeSecondRun, $afterSecondRun);
    }

    public function test_migration_command_encrypts_legacy_central_and_tenant_rows_idempotently(): void
    {
        DB::table('settings')->insert([
            'key' => 'onip_app_secret',
            'value' => 'legacy-onip-secret',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $centralPaymentId = DB::table('payments')->insertGetId([
            'name' => 'Legacy gateway',
            'gateway' => Payment::GATEWAY_EPAY_ALIPAY,
            'enable' => true,
            'fee_percent' => 0,
            'fee_fixed' => 0,
            'config' => json_encode(['gateway_url' => 'https://pay.example.com', 'key' => 'legacy-pay-secret']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tenant = Tenant::create(['id' => 'encrypted-shop']);
        $tenant->run(function () {
            DB::table('tenant_settings')->insert([
                'key' => 'telegram_bot_token',
                'value' => 'legacy-tenant-token',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('payments')->insert([
                'name' => 'Tenant gateway',
                'gateway' => Payment::GATEWAY_EPAY_ALIPAY,
                'enable' => true,
                'fee_percent' => 0,
                'fee_fixed' => 0,
                'config' => json_encode(['pid' => '1001', 'key' => 'legacy-tenant-pay-secret']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertSame('legacy-onip-secret', Setting::get('onip_app_secret'));
        Artisan::call('security:encrypt-sensitive-config');

        $centralSettingRaw = DB::table('settings')->where('key', 'onip_app_secret')->value('value');
        $centralPaymentRaw = DB::table('payments')->where('id', $centralPaymentId)->value('config');
        $this->assertTrue(EncryptedValue::isEncrypted($centralSettingRaw));
        $this->assertStringNotContainsString('legacy-pay-secret', $centralPaymentRaw);
        $this->assertSame('legacy-onip-secret', Setting::get('onip_app_secret'));

        $tenantRaw = $tenant->run(function () {
            $setting = DB::table('tenant_settings')->where('key', 'telegram_bot_token')->value('value');
            $payment = DB::table('payments')->value('config');

            $this->assertSame('legacy-tenant-token', Setting::get('telegram_bot_token'));

            return [$setting, $payment];
        });

        $this->assertTrue(EncryptedValue::isEncrypted($tenantRaw[0]));
        $this->assertStringNotContainsString('legacy-tenant-pay-secret', $tenantRaw[1]);

        $beforeSecondRun = [$centralSettingRaw, $centralPaymentRaw, ...$tenantRaw];
        Artisan::call('security:encrypt-sensitive-config');
        $afterSecondRun = [
            DB::table('settings')->where('key', 'onip_app_secret')->value('value'),
            DB::table('payments')->where('id', $centralPaymentId)->value('config'),
            ...$tenant->run(fn () => [
                DB::table('tenant_settings')->where('key', 'telegram_bot_token')->value('value'),
                DB::table('payments')->value('config'),
            ]),
        ];

        $this->assertSame($beforeSecondRun, $afterSecondRun);
    }
}
