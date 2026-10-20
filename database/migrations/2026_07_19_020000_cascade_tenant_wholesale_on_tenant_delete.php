<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clean rows left by tenant deletions that predate these constraints.
        DB::table('tenant_wholesale_settlements')
            ->whereNotIn('tenant_id', DB::table('tenants')->select('id'))
            ->delete();
        DB::table('tenant_wholesale')
            ->whereNotIn('tenant_id', DB::table('tenants')->select('id'))
            ->delete();

        Schema::table('tenant_wholesale', function (Blueprint $table) {
            $table->foreign('tenant_id', 'tenant_wholesale_tenant_fk')
                ->references('id')->on('tenants')
                ->cascadeOnUpdate()->cascadeOnDelete();
        });

        Schema::table('tenant_wholesale_settlements', function (Blueprint $table) {
            $table->foreign('tenant_id', 'tenant_wholesale_settlements_tenant_fk')
                ->references('id')->on('tenants')
                ->cascadeOnUpdate()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_wholesale_settlements', function (Blueprint $table) {
            $table->dropForeign('tenant_wholesale_settlements_tenant_fk');
        });

        Schema::table('tenant_wholesale', function (Blueprint $table) {
            $table->dropForeign('tenant_wholesale_tenant_fk');
        });
    }
};
