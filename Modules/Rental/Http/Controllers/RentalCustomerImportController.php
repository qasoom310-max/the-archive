<?php

declare(strict_types=1);

namespace Modules\Rental\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Rental\Support\CustomerImporter;

/**
 * Uploads a customer CSV (an export from a previous system) and imports it into
 * the shared rental customer store via {@see CustomerImporter}. Hostinger-safe
 * direct POST; manager-gated.
 *
 * The SAME endpoint serves both apps' Import buttons — a customer is one
 * shared record whichever app books them — so the redirect target is
 * whichever customer list the upload came from, not a single hard-coded one.
 */
final class RentalCustomerImportController
{
    /** @var list<string> */
    private const ALLOWED_REDIRECTS = ['/app/rental/customer', '/app/limousine/customer'];

    public function __invoke(Request $request, CustomerImporter $importer): RedirectResponse
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canApproveMaintenance(), 403);

        // No strict `mimes:csv` — Excel/Windows often report a CSV as text/plain
        // or application/vnd.ms-excel, which that rule wrongly rejects.
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:16384'],
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['file'];

        $result = $importer->import($file->getRealPath());

        $redirect = (string) $request->input('redirect', '/app/rental/customer');
        if (! in_array($redirect, self::ALLOWED_REDIRECTS, true)) {
            $redirect = '/app/rental/customer';
        }

        return redirect($redirect)->with('toast', __(
            ':imported customers imported, :skipped duplicates skipped.',
            ['imported' => $result['imported'], 'skipped' => $result['skipped']],
        ));
    }
}
