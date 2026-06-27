<?php

declare(strict_types=1);

namespace Modules\Rental\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Rental\Support\CarImporter;

/**
 * Uploads a cars CSV (an export from a previous system) and imports it into the
 * rental fleet via {@see CarImporter}. Hostinger-safe direct POST; manager-gated.
 */
final class RentalVehicleImportController
{
    public function __invoke(Request $request, CarImporter $importer): RedirectResponse
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canApproveMaintenance(), 403);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:8192'],
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['file'];

        $result = $importer->import($file->getRealPath());

        return redirect('/app/rental/vehicle')->with('toast', __(
            ':imported cars imported, :skipped duplicates skipped.',
            ['imported' => $result['imported'], 'skipped' => $result['skipped']],
        ));
    }
}
