<?php

declare(strict_types=1);

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumToken;

/**
 * The staff app's sign-in tokens — always on MAIN, the identity store every
 * login lives in. A data request switches the default connection to the
 * person's business database (App\Http\Middleware\AppWorkspace) only after the
 * token was checked; pinning the model keeps any later token read or revoke
 * (signing out) on Main too, never against an unrelated tenant row.
 */
final class PersonalAccessToken extends SanctumToken
{
    public function getConnectionName(): string
    {
        return Workspace::$landlordConnection;
    }
}
