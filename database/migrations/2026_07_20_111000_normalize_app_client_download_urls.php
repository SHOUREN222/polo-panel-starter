<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        $disk = Storage::disk('public');

        DB::table('app_clients')
            ->where('source_type', 'github')
            ->whereNotNull('local_path')
            ->orderBy('id')
            ->each(function (object $client) use ($disk): void {
                $path = (string) $client->local_path;

                if (! str_starts_with($path, 'app-downloads/')) {
                    return;
                }

                DB::table('app_clients')->where('id', $client->id)->update([
                    'url' => $disk->url($path),
                ]);
            });
    }

    public function down(): void
    {
        // Historical origin URLs may be dead or unsafe, so they are not restored.
    }
};
