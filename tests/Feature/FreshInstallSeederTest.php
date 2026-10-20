<?php

namespace Tests\Feature;

use App\Models\KnowledgeArticle;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class FreshInstallSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_seed_keeps_a_fresh_install_empty(): void
    {
        $this->seed();

        foreach (['users', 'orders', 'plans', 'servers', 'payments', 'subscription_instances'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }

        $this->assertGreaterThan(0, KnowledgeArticle::count());
        $this->assertSame(0, KnowledgeArticle::where('show', true)->count());
    }

    public function test_demo_seed_is_rejected_outside_local_and_testing(): void
    {
        $originalEnvironment = app()->environment();
        $this->app->detectEnvironment(fn () => 'production');

        try {
            (new DemoSeeder)->run();
            $this->fail('DemoSeeder unexpectedly ran in production.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'DemoSeeder is available only in local and testing environments.',
                $exception->getMessage(),
            );
        } finally {
            $this->app->detectEnvironment(fn () => $originalEnvironment);
        }
    }
}
