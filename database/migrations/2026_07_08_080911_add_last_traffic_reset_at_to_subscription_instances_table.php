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
        Schema::table('subscription_instances', function (Blueprint $table) {
            $table->date('last_traffic_reset_at')->nullable()->after('traffic_reminder_sent_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_instances', function (Blueprint $table) {
            $table->dropColumn('last_traffic_reset_at');
        });
    }
};
