<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Back-fill: emails used to be stored exactly as typed, and SQLite compares
 * strings case-sensitively, so a customer who registered as "Me@QQ.com" could
 * not log in, reset their password, or use a magic link with "me@qq.com" —
 * every lookup is an exact match and silently found nobody (the auth screens
 * deliberately don't say "no such account", so it looked like mail was lost).
 *
 * User::email() now lowercases on write; this fixes the rows that predate it.
 *
 * A row whose lowercase form would collide with an existing account is left
 * untouched — the unique index would reject the update, and merging two
 * accounts is not a decision a migration should make silently.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('users')->select('id', 'email')->get() as $row) {
            $lower = strtolower(trim((string) $row->email));

            if ($lower === $row->email) {
                continue;
            }

            $collides = DB::table('users')
                ->where('email', $lower)
                ->where('id', '!=', $row->id)
                ->exists();

            if ($collides) {
                continue;
            }

            DB::table('users')->where('id', $row->id)->update(['email' => $lower]);
        }
    }

    public function down(): void
    {
        // The original casing isn't recoverable, and lowercase is the canonical
        // form the app now relies on — nothing to reverse.
    }
};
