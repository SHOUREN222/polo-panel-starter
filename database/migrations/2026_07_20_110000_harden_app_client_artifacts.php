<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_clients', function (Blueprint $table) {
            $table->string('published_sha256', 64)->nullable()->after('synced_at');
            $table->unsignedBigInteger('published_size')->nullable()->after('published_sha256');
            $table->timestamp('published_verified_at')->nullable()->after('published_size');
            $table->string('pending_path')->nullable()->after('published_verified_at');
            $table->string('pending_version')->nullable()->after('pending_path');
            $table->string('pending_sha256', 64)->nullable()->after('pending_version');
            $table->unsignedBigInteger('pending_size')->nullable()->after('pending_sha256');
            $table->string('pending_source_digest', 64)->nullable()->after('pending_size');
            $table->timestamp('pending_at')->nullable()->after('pending_source_digest');
            $table->timestamp('last_sync_attempt_at')->nullable()->after('pending_at');
            $table->string('last_sync_error', 1000)->nullable()->after('last_sync_attempt_at');
        });

        // Preserve current downloads while recording a baseline. These hashes
        // describe legacy files; published_verified_at deliberately remains
        // null until an admin compares one against an independent trusted source.
        $disk = Storage::disk('public');

        DB::table('app_clients')->whereNotNull('local_path')->orderBy('id')->each(
            function (object $client) use ($disk): void {
                $path = (string) $client->local_path;

                if (! str_starts_with($path, 'app-downloads/') || ! $disk->exists($path)) {
                    return;
                }

                $absolutePath = $disk->path($path);
                $sha256 = hash_file('sha256', $absolutePath);
                $size = filesize($absolutePath);

                if (! is_string($sha256) || $size === false) {
                    return;
                }

                DB::table('app_clients')->where('id', $client->id)->update([
                    'published_sha256' => $sha256,
                    'published_size' => $size,
                ]);
            },
        );
    }

    public function down(): void
    {
        Schema::table('app_clients', function (Blueprint $table) {
            $table->dropColumn([
                'published_sha256',
                'published_size',
                'published_verified_at',
                'pending_path',
                'pending_version',
                'pending_sha256',
                'pending_size',
                'pending_source_digest',
                'pending_at',
                'last_sync_attempt_at',
                'last_sync_error',
            ]);
        });
    }
};
