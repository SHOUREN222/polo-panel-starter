<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_clients', function (Blueprint $table) {
            // Per-client download note shown as small text under the app's
            // name in the customer download modal (e.g. "付费·需美区 Apple ID").
            $table->string('note')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('app_clients', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
