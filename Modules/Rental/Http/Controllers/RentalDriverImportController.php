<?php

declare(strict_types=1);

namespace Modules\Rental\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Rental\Support\DriverImporter;

/**
 * Uploads a driver CSV (an export from a previous system) and imports it into
 * the shared driver store via {@see DriverImporter}. Hostinger-safe direct
 * POST; manager-gated.
 *
 * The SAME endpoint serves both apps' Import buttons — Rent A Car and
 * Limousine drivers are one store — so the redirect target is whichever
 * driver list the upload came from, not a single hard-coded one.
 */
final class RentalDriverImportController
{
    /** @var list<string> */
    private const ALLOWED_REDIRECTS = ['/app/rental/driver', '/app/limousine/driver'];

    public function __invoke(Request $request, DriverImporter $importer): RedirectResponse
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canApproveMaintenance(), 403);

        // No strict `mimes:csv` — Excel/Windows often report a CSV as
        // text/plain or application/vnd.ms-excel, which that rule wrongly rejects.
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:16384'],
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['file'];

        $result = $importer->import($file->getRealPath());

        $redirect = (string) $request->input('redirect', '/app/rental/driver');
        if (! in_array($redirect, self::ALLOWED_REDIRECTS, true)) {
            $redirect = '/app/rental/driver';
        }

        return redirect($redirect)->with('toast', __(
            ':imported drivers imported, :skipped duplicates skipped.',
            ['imported' => $result['imported'], 'skipped' => $result['skipped']],
        ));
    }
}
