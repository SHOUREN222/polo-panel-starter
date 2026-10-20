<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the hottest read paths that were doing full scans:
 *
 *  - orders(status, paid_at): the dashboard runs ~8 revenue/pending queries
 *    per load, all filtering on status (+ a paid_at range). The enum status
 *    and paid_at were unindexed.
 *  - traffic_logs(date): the fastest-growing table. Dashboard rankings and
 *    monthly sums filter by date alone, which isn't the leftmost column of
 *    the existing (user_id, …, date) unique index, so it couldn't be used.
 *
 * Pure index adds — no data touched, fully reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['status', 'paid_at'], 'orders_status_paid_at_index');
        });

        Schema::table('traffic_logs', function (Blueprint $table) {
            $table->index('date', 'traffic_logs_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_status_paid_at_index');
        });

        Schema::table('traffic_logs', function (Blueprint $table) {
            $table->dropIndex('traffic_logs_date_index');
        });
    }
};
