<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant payment gateways — each 分站 configures its OWN gateway (the
 * agent's EPay pid/key etc.) so customer money goes to the agent, not the
 * platform. `config` holds the gateway credentials.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('gateway');
            $table->boolean('enable')->default(false);
            $table->string('icon_url')->nullable();
            $table->string('notify_domain')->nullable();
            $table->decimal('fee_percent', 5, 2)->default(0);
            $table->bigInteger('fee_fixed')->default(0);
            $table->json('config')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
