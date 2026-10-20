<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant commerce tables (orders / 订阅实例 / 卡密 / 优惠券 / 余额流水), one
 * copy in each 分站's own SQLite DB. Deliberately NO foreign-key constraints:
 * some columns point at central tables (plan_id → central plans) which SQLite
 * can't FK across DB files, and within-tenant relations are enforced in app
 * code anyway. Column types mirror the central schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('paid_by_agent_id')->nullable();
            $table->unsignedBigInteger('plan_id')->nullable();          // → central plans
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->unsignedBigInteger('coupon_id')->nullable();
            $table->unsignedBigInteger('renew_subscription_instance_id')->nullable();
            $table->string('type');
            $table->string('period')->nullable();
            $table->string('trade_no')->unique();
            $table->bigInteger('total_amount')->default(0);
            $table->bigInteger('discount_amount')->default(0);
            $table->bigInteger('balance_amount')->default(0);
            $table->bigInteger('balance_credit_amount')->default(0);
            $table->string('status')->default('pending');
            $table->string('pack_kind')->nullable();
            $table->unsignedBigInteger('pack_bytes')->nullable();
            $table->json('selected_resource_profile')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index('user_id');
        });

        Schema::create('subscription_instances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('plan_id');                      // → central plans
            $table->unsignedBigInteger('created_order_id')->nullable();
            $table->unsignedBigInteger('last_order_id')->nullable();
            $table->string('token')->nullable()->unique();
            $table->string('uuid')->nullable()->unique();
            $table->unsignedBigInteger('transfer_enable')->default(0);
            $table->unsignedBigInteger('u')->default(0);
            $table->unsignedBigInteger('d')->default(0);
            $table->string('status')->default('pending');
            $table->timestamp('expired_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('expire_reminder_sent_at')->nullable();
            $table->timestamp('traffic_reminder_sent_at')->nullable();
            $table->timestamp('last_traffic_reset_at')->nullable();
            $table->timestamps();
            $table->index('user_id');
        });

        Schema::create('gift_cards', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->bigInteger('amount');
            $table->boolean('redeemed')->default(false);
            $table->unsignedBigInteger('redeemed_by_user_id')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->unsignedBigInteger('created_by_agent_id')->nullable();
            $table->unsignedBigInteger('agent_cost')->nullable();
            $table->timestamps();
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('type');
            $table->bigInteger('value');
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedInteger('per_user_limit')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('traffic_packs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('subscription_instance_id')->nullable();
            $table->string('kind')->default('one_time');
            $table->unsignedBigInteger('bytes')->default(0);
            $table->timestamps();
            $table->index('user_id');
        });

        Schema::create('provision_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('subscription_instance_id')->nullable();
            $table->string('status')->default('pending');
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
        });

        Schema::create('balance_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->bigInteger('amount');                               // signed: deductions negative
            $table->unsignedBigInteger('balance_after');
            $table->string('type');
            $table->string('note')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->timestamps();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balance_transactions');
        Schema::dropIfExists('traffic_packs');
        Schema::dropIfExists('provision_tasks');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('gift_cards');
        Schema::dropIfExists('subscription_instances');
        Schema::dropIfExists('orders');
    }
};
