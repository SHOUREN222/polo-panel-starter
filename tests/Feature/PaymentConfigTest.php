<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_payment_method(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        \Livewire\Livewire::actingAs($admin)->test('admin.payments')
            ->call('create')
            ->set('name', '模拟支付')
            ->set('gateway', 'mock')
            ->call('save');

        $this->assertDatabaseHas('payments', ['name' => '模拟支付', 'gateway' => 'mock', 'enable' => true]);
    }

    public function test_admin_can_toggle_payment_method(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $payment = Payment::create(['name' => '模拟支付', 'gateway' => 'mock', 'enable' => true]);

        \Livewire\Livewire::actingAs($admin)->test('admin.payments')
            ->call('toggle', $payment->id);

        $this->assertFalse($payment->fresh()->enable);
    }

    public function test_admin_can_delete_a_payment_method(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $payment = Payment::create(['name' => 'x', 'gateway' => 'mock', 'enable' => true]);

        \Livewire\Livewire::actingAs($admin)->test('admin.payments')
            ->call('delete', $payment->id);

        $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
    }

    public function test_fixed_fee_is_entered_in_yuan_and_stored_in_cents(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        // Admin types "2" meaning ¥2 — it must be stored as 200 cents so that
        // amountWithFee() (which works in cents) adds ¥2, not ¥0.02.
        \Livewire\Livewire::actingAs($admin)->test('admin.payments')
            ->call('create')
            ->set('name', '模拟支付')
            ->set('gateway', 'mock')
            ->set('fee_fixed', '2')
            ->call('save');

        $payment = Payment::where('name', '模拟支付')->first();
        $this->assertSame(200, $payment->fee_fixed);
        // And amountWithFee adds exactly ¥2 (200 cents) on a ¥30 order.
        $this->assertSame(3200, $payment->amountWithFee(3000));
    }

    public function test_editing_shows_fixed_fee_back_in_yuan(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $payment = Payment::create(['name' => 'x', 'gateway' => 'mock', 'enable' => true, 'fee_fixed' => 250]);

        \Livewire\Livewire::actingAs($admin)->test('admin.payments')
            ->call('edit', $payment->id)
            ->assertSet('fee_fixed', '2.50');
    }

    public function test_real_gateway_requires_its_credential_fields(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        // Selecting EPay but leaving pid/key blank must be rejected at save,
        // not silently stored (which would only 503 later at checkout).
        \Livewire\Livewire::actingAs($admin)->test('admin.payments')
            ->call('create')
            ->set('name', '支付宝')
            ->set('gateway', Payment::GATEWAY_EPAY_ALIPAY)
            ->call('save')
            ->assertHasErrors(['cfg_pid', 'cfg_key']);

        $this->assertDatabaseMissing('payments', ['name' => '支付宝']);
    }

    public function test_real_gateway_saves_when_credentials_filled(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        \Livewire\Livewire::actingAs($admin)->test('admin.payments')
            ->call('create')
            ->set('name', '支付宝')
            ->set('gateway', Payment::GATEWAY_EPAY_ALIPAY)
            ->set('cfg_gateway_url', 'https://pay.example.com')
            ->set('cfg_pid', '10001')
            ->set('cfg_key', 'secret-key')
            ->call('save')
            ->assertHasNoErrors();

        $payment = Payment::where('name', '支付宝')->first();
        $this->assertSame('10001', $payment->config['pid']);
    }
}
