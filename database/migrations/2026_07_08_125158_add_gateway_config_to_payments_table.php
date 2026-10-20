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
        Schema::table('payments', function (Blueprint $table) {
            $table->string('icon_url')->nullable()->after('gateway');
            $table->string('notify_domain')->nullable()->after('icon_url');
            $table->decimal('fee_percent', 5, 2)->default(0)->after('notify_domain');
            $table->unsignedInteger('fee_fixed')->default(0)->after('fee_percent');
            $table->json('config')->nullable()->after('fee_fixed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['icon_url', 'notify_domain', 'fee_percent', 'fee_fixed', 'config']);
        });
    }
};
