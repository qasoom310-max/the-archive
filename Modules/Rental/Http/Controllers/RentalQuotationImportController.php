<?php

declare(strict_types=1);

namespace Modules\Rental\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Rental\Support\QuotationImporter;

/**
 * Uploads a quotations CSV (an export from a previous system, the same shape
 * this screen's own export already prints) and imports it via {@see
 * QuotationImporter}. Hostinger-safe direct POST; manager-gated.
 */
final class RentalQuotationImportController
{
    public function __invoke(Request $request, QuotationImporter $importer): RedirectResponse
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canApproveMaintenance(), 403);

        $validated = $request->validate(['file' => ['required', 'file', 'max:16384']]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['file'];

        $result = $importer->import($file->getRealPath());

        return redirect('/app/rental/quotation')->with('toast', __(
            ':imported quotations imported, :skipped already on file skipped.',
            ['imported' => $result['imported'], 'skipped' => $result['skipped']],
        ));
    }
}
