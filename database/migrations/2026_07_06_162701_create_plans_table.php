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
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['shared', 'dedicated_isp']);
            $table->unsignedInteger('group_id')->nullable();
            $table->unsignedBigInteger('transfer_enable')->default(0);
            $table->unsignedInteger('speed_limit')->nullable();
            $table->unsignedInteger('device_limit')->nullable();
            $table->json('prices');
            $table->json('resource_profile')->nullable();
            $table->boolean('sell')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
