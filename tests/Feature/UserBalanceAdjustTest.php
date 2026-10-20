<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserBalanceAdjustTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_add_and_deduct_user_balance_with_a_note(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create(['balance' => 1000]); // ¥10

        // add ¥20
        Livewire::actingAs($admin)->test('admin.users')
            ->call('startAdjust', $user->id)
            ->set('adjustAmount', '20')->set('adjustNote', '客服补偿')
            ->call('saveAdjust');
        $this->assertSame(3000, $user->fresh()->balance);

        // deduct ¥5
        Livewire::actingAs($admin)->test('admin.users')
            ->call('startAdjust', $user->id)
            ->set('adjustAmount', '-5')->set('adjustNote', '手动扣')
            ->call('saveAdjust');
        $this->assertSame(2500, $user->fresh()->balance);
    }

    public function test_balance_never_goes_below_zero(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create(['balance' => 500]);

        Livewire::actingAs($admin)->test('admin.users')
            ->call('startAdjust', $user->id)
            ->set('adjustAmount', '-100')->set('adjustNote', '扣爆')
            ->call('saveAdjust');

        $this->assertSame(0, $user->fresh()->balance);
    }

    public function test_note_is_required(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $user = User::factory()->create(['balance' => 0]);

        Livewire::actingAs($admin)->test('admin.users')
            ->call('startAdjust', $user->id)
            ->set('adjustAmount', '20')->set('adjustNote', '')
            ->call('saveAdjust')
            ->assertHasErrors('adjustNote');

        $this->assertSame(0, $user->fresh()->balance);
    }
}
