<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // null = normal user; 'super' = 全权限; 'support' = 受限客服.
            $table->string('admin_role', 20)->nullable()->after('is_admin');
        });

        // Existing admins keep full access — they become 超级管理员.
        DB::table('users')->where('is_admin', true)->update(['admin_role' => 'super']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('admin_role');
        });
    }
};
