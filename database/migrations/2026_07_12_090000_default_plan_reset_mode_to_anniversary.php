<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The "每月固定日" (fixed_day) reset mode has been retired — all shared
     * plans now reset on each customer's own purchase day (anniversary).
     * Flip the column default and migrate any lingering fixed_day rows so a
     * plan created without an explicit mode gets the new behavior.
     */
    public function up(): void
    {
        DB::table('plans')->where('traffic_reset_mode', 'fixed_day')
            ->update(['traffic_reset_mode' => 'anniversary']);

        Schema::table('plans', function ($table) {
            $table->string('traffic_reset_mode')->default('anniversary')->change();
        });
    }

    public function down(): void
    {
        Schema::table('plans', function ($table) {
            $table->string('traffic_reset_mode')->default('fixed_day')->change();
        });
    }
};
