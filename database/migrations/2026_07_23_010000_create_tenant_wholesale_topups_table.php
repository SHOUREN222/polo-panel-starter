<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Unlike tenant_wholesale_settlements (order-driven debits), credit()
        // previously just incremented a balance column with no log row — a
        // 分站 admin could see their current balance but never how it got
        // there. This is that missing credit-side history.
        Schema::create('tenant_wholesale_topups', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->bigInteger('amount'); // face value credited, cents
            $table->bigInteger('cash_collected')->nullable(); // what 站长 actually charged, cents
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->foreign('tenant_id', 'tenant_wholesale_topups_tenant_fk')
                ->references('id')->on('tenants')
                ->cascadeOnUpdate()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_wholesale_topups');
    }
};
