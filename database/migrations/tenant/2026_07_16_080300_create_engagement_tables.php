<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant engagement tables: invites/commissions, support tickets, and
 * traffic logs — each 分站's own. No cross-DB FKs (relations enforced in code).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invite_codes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('code')->unique();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('referred_user_id');
            $table->unsignedBigInteger('order_id');
            $table->bigInteger('amount');
            $table->string('status')->default('pending');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('transferred_at')->nullable();
            $table->timestamps();
            $table->index('user_id');
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('subject');
            $table->string('status')->default('open');
            $table->string('level')->nullable();
            $table->timestamps();
            $table->index('user_id');
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id');
            $table->unsignedBigInteger('user_id');
            $table->text('content');
            $table->timestamps();
            $table->index('ticket_id');
        });

        Schema::create('traffic_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('subscription_instance_id')->nullable();
            $table->unsignedBigInteger('server_id')->nullable();
            $table->date('date');
            $table->unsignedBigInteger('upload_bytes')->default(0);
            $table->unsignedBigInteger('download_bytes')->default(0);
            $table->decimal('rate', 8, 2)->default(1);
            $table->timestamps();
            $table->index(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        foreach (['traffic_logs', 'ticket_messages', 'tickets', 'commissions', 'invite_codes'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
