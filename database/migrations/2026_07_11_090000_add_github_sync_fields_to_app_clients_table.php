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
        Schema::table('app_clients', function (Blueprint $table) {
            $table->string('source_type')->default('manual');
            $table->string('github_repo')->nullable();
            $table->string('asset_pattern')->nullable();
            $table->string('local_path')->nullable();
            $table->string('synced_version')->nullable();
            $table->timestamp('synced_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('app_clients', function (Blueprint $table) {
            $table->dropColumn(['source_type', 'github_repo', 'asset_pattern', 'local_path', 'synced_version', 'synced_at']);
        });
    }
};
