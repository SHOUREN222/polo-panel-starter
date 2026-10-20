<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class UserAccessRevoker
{
    /** Revoke every credential that can outlive the current request. */
    public function revoke(User $user): void
    {
        $connection = $user->getConnectionName() ?: DB::getDefaultConnection();

        if (Schema::connection($connection)->hasTable('personal_access_tokens')) {
            $user->tokens()->delete();
        }

        $sessionConnection = config('session.connection') ?: DB::getDefaultConnection();
        $sessionTable = (string) config('session.table', 'sessions');

        if (Schema::connection($sessionConnection)->hasTable($sessionTable)) {
            DB::connection($sessionConnection)
                ->table($sessionTable)
                ->where('user_id', $user->getAuthIdentifier())
                ->delete();
        }

        // Invalidates remember-me cookies even when the session driver cannot
        // enumerate sessions (for example, file or cookie sessions).
        $user->setRememberToken(Str::random(60));
        $user->saveQuietly();
    }
}
