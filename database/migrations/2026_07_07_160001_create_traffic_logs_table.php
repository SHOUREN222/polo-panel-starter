<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traffic_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_instance_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('server_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('upload_bytes')->default(0);
            $table->unsignedBigInteger('download_bytes')->default(0);
            $table->decimal('rate', 5, 2)->default(1.00);
            $table->timestamps();

            $table->unique(['user_id', 'subscription_instance_id', 'server_id', 'date'], 'traffic_logs_unique_day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_logs');
    }
};
