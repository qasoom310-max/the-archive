<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Erp\Backup\DatabaseBackup;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download a single database snapshot as a file. Admin-only, and the requested
 * path must be a snapshot the ACTIVE database owns — so no one can pull another
 * workspace's backup by guessing a path.
 */
final class BackupDownloadController extends Controller
{
    public function __invoke(Request $request, DatabaseBackup $backup): StreamedResponse
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->isAdmin(), 403);

        $path = (string) $request->query('file', '');
        abort_unless($backup->owns($path), 404);

        return Storage::disk('local')->download($path, basename($path));
    }
}
