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
        Schema::table('orders', function (Blueprint $table) {
            // Set only for renewal orders: which existing instance this order
            // extends. Null for 'new' orders (those always provision a fresh instance).
            $table->foreignId('renew_subscription_instance_id')
                ->nullable()
                ->after('plan_id')
                ->constrained('subscription_instances')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('renew_subscription_instance_id');
        });
    }
};
