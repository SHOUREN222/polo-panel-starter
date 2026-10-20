<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('show');
            $table->string('heartbeat_secret')->nullable()->unique()->after('last_seen_at');
        });

        \App\Models\Server::whereNull('heartbeat_secret')->each(function ($server) {
            $server->update(['heartbeat_secret' => (string) Str::uuid()]);
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn(['last_seen_at', 'heartbeat_secret']);
        });
    }
};
