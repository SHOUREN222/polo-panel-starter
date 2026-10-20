<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routing_rules', function (Blueprint $table) {
            // Which node type the rule applies to — shared (V2bX), isp (relay),
            // or both. Lets e.g. ad-blocking target shared browsing only,
            // leaving static-ISP (account-registration) traffic untouched.
            $table->string('scope')->default('both')->after('action');
        });
    }

    public function down(): void
    {
        Schema::table('routing_rules', function (Blueprint $table) {
            $table->dropColumn('scope');
        });
    }
};
