<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CaddyOnDemandTest extends TestCase
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

    private function ask(string $domain): TestResponse
    {
        return $this->get('/api/caddy-ondemand-ask?domain='.urlencode($domain));
    }

    public function test_the_main_site_domain_is_allowed(): void
    {
        $this->ask('app.example.test')->assertOk();
    }

    public function test_a_registered_tenant_subdomain_is_allowed(): void
    {
        Tenant::create(['id' => 'daili1'])->domains()->create(['domain' => 'daili1']);

        $this->ask('daili1.example.test')->assertOk();
    }

    public function test_an_unregistered_subdomain_is_refused(): void
    {
        // No 分站 named "random" exists → Caddy must NOT get a cert for it.
        $this->ask('random.example.test')->assertForbidden();
    }

    public function test_a_foreign_domain_is_refused(): void
    {
        // Even if someone points evil.com at us, the label check fails.
        Tenant::create(['id' => 'daili1'])->domains()->create(['domain' => 'daili1']);

        $this->ask('daili1.evil.com')->assertForbidden();
        $this->ask('example.test.evil.com')->assertForbidden();
    }

    public function test_a_deleted_tenant_subdomain_stops_being_allowed(): void
    {
        $t = Tenant::create(['id' => 'gone']);
        $t->domains()->create(['domain' => 'gone']);
        $this->ask('gone.example.test')->assertOk();

        $t->delete(); // closing the 分站 removes its domain row

        $this->ask('gone.example.test')->assertForbidden();
    }

    public function test_a_missing_domain_param_is_a_bad_request(): void
    {
        $this->get('/api/caddy-ondemand-ask')->assertStatus(400);
    }
}
