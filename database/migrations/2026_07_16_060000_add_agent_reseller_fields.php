<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 代理身份 + 进货折扣（百分比 0–90，表示生成卡密时按面值的几折从余额扣费）
            $table->boolean('is_agent')->default(false)->after('is_admin');
            $table->unsignedTinyInteger('agent_discount')->default(0)->after('is_agent');
        });

        Schema::table('gift_cards', function (Blueprint $table) {
            // 由哪个代理生成（null = 站长后台直接生成的卡）；agent_cost = 代理实际扣的余额（分）
            $table->foreignId('created_by_agent_id')->nullable()->after('redeemed_at')->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('agent_cost')->nullable()->after('created_by_agent_id');
        });
    }

    public function down(): void
    {
        Schema::table('gift_cards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_agent_id');
            $table->dropColumn('agent_cost');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_agent', 'agent_discount']);
        });
    }
};
