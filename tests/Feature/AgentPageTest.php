<?php

namespace Tests\Feature;

use App\Models\GiftCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AgentPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_agent_is_forbidden_from_the_agent_center(): void
    {
        $this->actingAs(User::factory()->create(['is_agent' => false]))
            ->get('/agent')
            ->assertForbidden();
    }

    public function test_agent_can_open_and_generate_cards_inline(): void
    {
        $agent = User::factory()->create(['is_agent' => true, 'agent_discount' => 10, 'balance' => 100000]);

        $this->actingAs($agent)->get('/agent')->assertOk()->assertSee('代理中心');

        Livewire::actingAs($agent)->test('agent')
            ->set('faceAmount', '50')
            ->set('quantity', '2')
            ->call('generate')
            ->assertHasNoErrors()
            ->assertSet('lastCodes', fn ($codes) => count($codes) === 2);

        $this->assertSame(2, GiftCard::where('created_by_agent_id', $agent->id)->count());
        // 2 × ¥50 × 90% = ¥90 charged.
        $this->assertSame(100000 - 9000, $agent->fresh()->balance);
    }

    public function test_agent_only_sees_their_own_cards(): void
    {
        $a1 = User::factory()->create(['is_agent' => true, 'balance' => 100000]);
        $a2 = User::factory()->create(['is_agent' => true, 'balance' => 100000]);
        GiftCard::create(['code' => 'AAAA-AAAA-AAAA', 'amount' => 1000, 'created_by_agent_id' => $a2->id]);

        Livewire::actingAs($a1)->test('agent')->assertDontSee('AAAA-AAAA-AAAA');
    }

    public function test_super_admin_can_promote_a_user_to_agent(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);
        $user = User::factory()->create(['is_agent' => false]);

        Livewire::actingAs($admin)->test('admin.users')
            ->call('viewUser', $user->id)
            ->set('agentEnabled', true)
            ->set('agentDiscount', '25')
            ->call('saveAgent', $user->id)
            ->assertHasNoErrors();

        $user->refresh();
        $this->assertTrue($user->is_agent);
        $this->assertSame(25, $user->agent_discount);
    }

    public function test_support_cannot_promote_to_agent(): void
    {
        $support = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPPORT]);
        $user = User::factory()->create(['is_agent' => false]);

        Livewire::actingAs($support)->test('admin.users')
            ->call('viewUser', $user->id)
            ->set('agentEnabled', true)
            ->call('saveAgent', $user->id);

        $this->assertFalse($user->fresh()->is_agent);
    }
}
