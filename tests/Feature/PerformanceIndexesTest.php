<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PerformanceIndexesTest extends TestCase
{
    use RefreshDatabase;

    private function indexNames(string $table): array
    {
        return array_map(fn ($i) => $i['name'], Schema::getIndexes($table));
    }

    public function test_orders_has_status_paid_at_index(): void
    {
        $this->assertContains('orders_status_paid_at_index', $this->indexNames('orders'));
    }

    public function test_traffic_logs_has_date_index(): void
    {
        $this->assertContains('traffic_logs_date_index', $this->indexNames('traffic_logs'));
    }
}
