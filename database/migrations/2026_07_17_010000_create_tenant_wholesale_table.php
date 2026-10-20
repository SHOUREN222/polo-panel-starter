<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A 分站's pre-funded 进货余额 (wholesale balance), central. The agent tops it
 * up (站长 credits it when the agent pays); each delivered 分站 order debits it
 * at the wholesale cost. Central table (CentralConnection model) so it's read/
 * written the same from central or tenant context — no tenancy switch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_wholesale', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->unique();       // = 分站 subdomain / tenant id
            $table->bigInteger('balance')->default(0);   // cents
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_wholesale');
    }
};
