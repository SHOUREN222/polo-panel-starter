<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant (分站) users table. Runs in each tenant's own SQLite DB, so `email`
 * is unique PER TENANT — a@x.com on 分站A and 分站B are different people.
 * Columns mirror the central users table; cross-DB references (plan_id,
 * invite_code_id — those live in central) are plain columns with NO FK, since
 * SQLite can't foreign-key across separate database files. Shared models
 * (Plan/Server/…) will be pinned to the central connection in app code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->string('uuid')->nullable()->unique();
            $table->string('token')->nullable()->unique();
            $table->unsignedBigInteger('balance')->default(0);
            $table->boolean('banned')->default(false);
            $table->boolean('is_admin')->default(false);
            $table->string('admin_role', 20)->nullable();
            $table->boolean('is_owner')->default(false);
            $table->boolean('is_agent')->default(false);
            $table->unsignedTinyInteger('agent_discount')->default(0);
            $table->unsignedBigInteger('plan_id')->nullable();          // → central plans (no cross-DB FK)
            $table->timestamp('plan_expired_at')->nullable();
            $table->date('shared_traffic_reset_at')->nullable();
            $table->unsignedBigInteger('referred_by_user_id')->nullable();
            $table->unsignedBigInteger('invite_code_id')->nullable();
            $table->boolean('notify_expire')->default(true);
            $table->boolean('notify_traffic')->default(true);
            $table->unsignedBigInteger('telegram_id')->nullable();
            $table->string('telegram_username')->nullable();
            $table->string('telegram_bind_code')->nullable();
            $table->timestamp('telegram_bind_code_expires_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip')->nullable();
            $table->text('admin_note')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            // Email is unique within the tenant DB (per-site namespace).
            $table->unique('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
