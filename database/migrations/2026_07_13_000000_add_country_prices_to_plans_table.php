<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // Per-country price overrides for dynamic-region ISP plans, keyed
            // countryCode => { period => cents }. Null/absent country falls back
            // to the plan's base `prices`.
            $table->json('country_prices')->nullable()->after('prices');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('country_prices');
        });
    }
};
