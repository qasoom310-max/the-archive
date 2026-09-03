<?php

declare(strict_types=1);

namespace Modules\Rental\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Rental\Support\MaintenanceImporter;

/**
 * Uploads a maintenance-records CSV (an export from a previous system, the
 * same shape this screen's own export already prints) and imports it via
 * {@see MaintenanceImporter}. Hostinger-safe direct POST; manager-gated.
 */
final class RentalMaintenanceImportController
{
    public function __invoke(Request $request, MaintenanceImporter $importer): RedirectResponse
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canApproveMaintenance(), 403);

        $validated = $request->validate(['file' => ['required', 'file', 'max:16384']]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['file'];

        $result = $importer->import($file->getRealPath());

        return redirect('/app/rental/maintenance')->with('toast', __(
            ':imported records imported, :skipped already on file skipped.',
            ['imported' => $result['imported'], 'skipped' => $result['skipped']],
        ));
    }
}
