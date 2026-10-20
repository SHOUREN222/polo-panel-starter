<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('traffic_packs', function (Blueprint $table) {
            // null = a shared-plan pack (counts toward the shared pool);
            // set = a dedicated-ISP pack that tops up that one line's quota.
            $table->foreignId('subscription_instance_id')->nullable()->after('plan_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('traffic_packs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subscription_instance_id');
        });
    }
};
