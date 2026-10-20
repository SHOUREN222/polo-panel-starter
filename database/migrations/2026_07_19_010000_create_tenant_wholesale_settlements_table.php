<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_wholesale_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->unsignedBigInteger('order_id');
            $table->string('trade_no');
            $table->bigInteger('amount');
            $table->string('status');
            $table->timestamp('reserved_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'order_id']);
            $table->index(['status', 'reserved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_wholesale_settlements');
    }
};
