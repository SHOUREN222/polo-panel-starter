<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->json('tags')->nullable()->after('name');
            $table->unsignedInteger('capacity_limit')->nullable()->after('device_limit');
            $table->string('traffic_reset_mode')->default('fixed_day')->after('capacity_limit');
            $table->text('description')->nullable()->after('resource_profile');
            $table->boolean('show')->default(true)->after('sell');
            $table->boolean('allow_renewal')->default(true)->after('sell');
            $table->unsignedInteger('traffic_pack_price_per_gb')->nullable()->after('allow_renewal');
            $table->unsignedInteger('traffic_reset_pack_price_per_gb')->nullable()->after('traffic_pack_price_per_gb');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'tags', 'capacity_limit', 'traffic_reset_mode', 'description',
                'show', 'allow_renewal', 'traffic_pack_price_per_gb', 'traffic_reset_pack_price_per_gb',
            ]);
        });
    }
};
