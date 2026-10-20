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
            // 站长：唯一能设置/变更「超级管理员」这一层的账号。其他超管管不了超管。
            $table->boolean('is_owner')->default(false)->after('admin_role');
        });

        // The original (lowest-id) admin becomes the owner and is pinned to super.
        $ownerId = DB::table('users')->where('is_admin', true)->orderBy('id')->value('id');
        if ($ownerId) {
            DB::table('users')->where('id', $ownerId)->update([
                'is_owner' => true,
                'admin_role' => 'super',
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_owner');
        });
    }
};
