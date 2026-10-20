<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantImpersonationToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class TenantImpersonationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenant()) {
            tenancy()->end();
        }
        foreach (Tenant::all() as $t) {
            $t->delete();
        }
        parent::tearDown();
    }

    private function makeTenantWithOwner(string $id, string $email = 'owner@example.com'): int
    {
        $t = Tenant::create(['id' => $id]);
        $t->domains()->create(['domain' => $id]);

        $ownerId = null;
        tenancy()->initialize($t);
        $owner = User::create(['name' => '代理', 'email' => $email, 'password' => 'x']);
        $owner->forceFill(['is_admin' => true, 'admin_role' => User::ROLE_SUPER, 'is_owner' => true])->save();
        $ownerId = $owner->id;
        tenancy()->end();

        return $ownerId;
    }

    public function test_super_admin_can_enter_a_tenants_admin_panel_and_use_its_tooling(): void
    {
        $superAdmin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);
        $ownerId = $this->makeTenantWithOwner('enter1');

        $component = Livewire::actingAs($superAdmin)->test('admin.tenants')
            ->call('enterTenant', 'enter1')
            ->assertRedirectContains('https://enter1.example.test/__enter-tenant-admin?');

        $url = $component->effects['redirect'];

        // Following the signed link logs the super admin in AS the 分站's
        // own admin, inside that tenant's DB — no separate 2FA prompt even
        // though this 分站 admin never set 2FA up themselves.
        $response = $this->get($url);
        $response->assertRedirect(route('admin.dashboard'));

        tenancy()->initialize(Tenant::find('enter1'));
        $this->assertAuthenticatedAs(User::find($ownerId));
        tenancy()->end();

        $base = 'http://enter1.example.test/'.config('admin.path');
        $this->get("$base/users")->assertOk(); // no "must enable 2FA" redirect
        $this->get($base)->assertOk();
    }

    public function test_only_a_super_admin_can_start_impersonation(): void
    {
        $support = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPPORT]);
        $this->makeTenantWithOwner('enter2');

        Livewire::actingAs($support)->test('admin.tenants')
            ->call('enterTenant', 'enter2')
            ->assertSet('error', fn ($e) => str_contains($e, '超级管理员'));
    }

    public function test_a_tampered_token_is_rejected(): void
    {
        $superAdmin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);
        $ownerId = $this->makeTenantWithOwner('enter3');

        $url = TenantImpersonationToken::url('enter3.example.test', 'enter3', $ownerId, $superAdmin->id);
        $tampered = str_replace((string) $ownerId, (string) ($ownerId + 999), $url);

        $this->get($tampered)->assertForbidden();
    }

    public function test_an_expired_token_is_rejected(): void
    {
        Carbon::setTestNow('2026-01-01 00:00:00');
        $superAdmin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);
        $ownerId = $this->makeTenantWithOwner('enter4');

        $url = TenantImpersonationToken::url('enter4.example.test', 'enter4', $ownerId, $superAdmin->id);

        Carbon::setTestNow('2026-01-01 00:05:00'); // past the 120s TTL
        $this->get($url)->assertForbidden();

        Carbon::setTestNow();
    }

    public function test_a_token_signed_for_one_tenant_does_not_work_on_another(): void
    {
        $superAdmin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);
        $ownerId = $this->makeTenantWithOwner('enter5');
        $this->makeTenantWithOwner('enter6');

        // Signed for enter5, but the URL host (and therefore the tenant
        // InitializeTenancyBySubdomain resolves) is enter6.
        $url = TenantImpersonationToken::url('enter6.example.test', 'enter5', $ownerId, $superAdmin->id);

        $this->get($url)->assertForbidden();
    }

    public function test_exit_ends_the_impersonated_session_and_returns_to_the_platform(): void
    {
        $superAdmin = User::factory()->create(['is_admin' => true, 'admin_role' => User::ROLE_SUPER]);
        $ownerId = $this->makeTenantWithOwner('enter7');

        $url = TenantImpersonationToken::url('enter7.example.test', 'enter7', $ownerId, $superAdmin->id);
        $this->get($url);

        $base = 'http://enter7.example.test/'.config('admin.path');
        $response = $this->post("$base/impersonation/exit");
        $response->assertRedirect('https://app.example.test/'.config('admin.path').'/tenants');

        tenancy()->initialize(Tenant::find('enter7'));
        $this->assertGuest();
        tenancy()->end();
    }
}
