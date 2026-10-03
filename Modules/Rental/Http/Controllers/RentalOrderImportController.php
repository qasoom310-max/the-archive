<?php

declare(strict_types=1);

namespace Modules\Rental\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Rental\Support\OrderImporter;

/**
 * Uploads an orders CSV (an export from a previous system, the same shape
 * this screen's own export already prints) and imports it via {@see
 * OrderImporter}. Hostinger-safe direct POST; manager-gated.
 */
final class RentalOrderImportController
{
    public function __invoke(Request $request, OrderImporter $importer): RedirectResponse
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

        $message = __(
            ':imported orders imported, :skipped already on file skipped.',
            ['imported' => $result['imported'], 'skipped' => $result['skipped']],
        );
        if ($result['reopened'] > 0) {
            $message .= ' ' . __(':count orders still active in the file were reopened.', ['count' => $result['reopened']]);
        }
        if ($result['failed'] > 0) {
            $message .= ' ' . __(':count rows could not be read and were skipped.', ['count' => $result['failed']]);
        }

        return redirect('/app/rental/order')->with('toast', $message);
    }
}
