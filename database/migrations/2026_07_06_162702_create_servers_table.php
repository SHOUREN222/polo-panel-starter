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
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('group_id');
            $table->string('host');
            $table->unsignedInteger('port');
            $table->enum('protocol', ['shadowsocks', 'vmess'])->default('shadowsocks');
            $table->string('method')->default('aes-256-gcm');
            $table->decimal('rate', 5, 2)->default(1.00);
            $table->boolean('show')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
