<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Limousine\Support\BookingImporter;

/**
 * Uploads a trips CSV (an export from a previous system, the same shape the
 * queue's own export already prints) and imports it via {@see BookingImporter}.
 * Hostinger-safe direct POST; manager-gated, the same convention as every
 * other import in the app.
 */
final class LimoBookingImportController
{
    public function __invoke(Request $request, BookingImporter $importer): RedirectResponse
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

        return redirect('/app/limousine/booking')->with('toast', __(
            ':imported trips imported, :skipped already on file skipped.',
            ['imported' => $result['imported'], 'skipped' => $result['skipped']],
        ));
    }
}
