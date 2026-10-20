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
            $table->date('expire_reminder_sent_at')->nullable()->after('expired_at');
            $table->date('traffic_reminder_sent_at')->nullable()->after('expire_reminder_sent_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_instances', function (Blueprint $table) {
            $table->dropColumn(['expire_reminder_sent_at', 'traffic_reminder_sent_at']);
        });
    }
};
