<?php

use App\Models\Server;
use App\Support\EncryptedValue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->string('heartbeat_secret_hash', 64)->nullable()->unique()->after('heartbeat_secret');
        });

        $connection = (new Server)->getConnectionName();
        $table = DB::connection($connection)->table('servers');

        $table->whereNotNull('heartbeat_secret')->orderBy('id')->each(function (object $server) use ($connection) {
            $secret = EncryptedValue::decrypt($server->heartbeat_secret);

            DB::connection($connection)->table('servers')->where('id', $server->id)->update([
                'heartbeat_secret' => EncryptedValue::encrypt($secret),
                'heartbeat_secret_hash' => hash('sha256', (string) $secret),
            ]);
        });
    }

    public function down(): void
    {
        $connection = (new Server)->getConnectionName();
        $table = DB::connection($connection)->table('servers');

        $table->whereNotNull('heartbeat_secret')->orderBy('id')->each(function (object $server) use ($connection) {
            DB::connection($connection)->table('servers')->where('id', $server->id)->update([
                'heartbeat_secret' => EncryptedValue::decrypt($server->heartbeat_secret),
            ]);
        });

        Schema::table('servers', function (Blueprint $table) {
            $table->dropUnique(['heartbeat_secret_hash']);
            $table->dropColumn('heartbeat_secret_hash');
        });
    }
};
