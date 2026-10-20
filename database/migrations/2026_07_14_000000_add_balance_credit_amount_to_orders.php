<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Unused shared-plan value (cents) beyond what the new order costs,
            // credited to the user's balance at settlement so switching/upgrading
            // never forfeits paid value. Capped at what the user actually paid.
            $table->integer('balance_credit_amount')->default(0)->after('balance_amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('balance_credit_amount');
        });
    }
};
