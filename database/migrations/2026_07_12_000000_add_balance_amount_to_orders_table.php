<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // How many cents of this order have already been paid from the
            // user's account balance. The remainder (total_amount minus this)
            // is what a payment gateway is asked to charge. Deducted from the
            // user's balance at apply-time and refunded on cancellation, so
            // this column is the single source of truth for "balance locked
            // into this order".
            $table->unsignedInteger('balance_amount')->default(0)->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('balance_amount');
        });
    }
};
