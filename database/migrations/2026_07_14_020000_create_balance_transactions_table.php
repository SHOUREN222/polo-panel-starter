<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('balance_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('amount');          // cents, + credit / − debit
            $table->integer('balance_after');   // cents, balance right after this change
            $table->string('type');             // adjust_admin | gift_card | commission | upgrade_credit | order_payment
            $table->string('note')->nullable(); // reason / reference
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete(); // who, for admin adjusts
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balance_transactions');
    }
};
