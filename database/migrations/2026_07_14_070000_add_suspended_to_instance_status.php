<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Widen the enum to include 'suspended' (auto-cut for over-quota ISP
        // lines). Plain string avoids future enum-migration pain — statuses are
        // already guarded by SubscriptionInstance::STATUS_* constants in code.
        Schema::table('subscription_instances', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->change();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_instances', function (Blueprint $table) {
            $table->enum('status', ['pending', 'active', 'disabled'])->default('pending')->change();
        });
    }
};
