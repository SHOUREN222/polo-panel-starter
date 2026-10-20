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
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('telegram_id')->nullable()->unique()->after('uuid');
            $table->string('telegram_username')->nullable()->after('telegram_id');
            $table->string('telegram_bind_code', 8)->nullable()->unique()->after('telegram_username');
            $table->timestamp('telegram_bind_code_expires_at')->nullable()->after('telegram_bind_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['telegram_id', 'telegram_username', 'telegram_bind_code', 'telegram_bind_code_expires_at']);
        });
    }
};
