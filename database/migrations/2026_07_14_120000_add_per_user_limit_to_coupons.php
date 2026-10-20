<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            // Max times a SINGLE user may use this coupon (null = unlimited).
            // Separate from max_uses (the global cap) so a "one per customer"
            // discount can't be reused by the same account on every renewal.
            $table->integer('per_user_limit')->nullable()->after('max_uses');
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn('per_user_limit');
        });
    }
};
