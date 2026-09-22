<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Limousine\Support\InvoiceImporter;
use Modules\Limousine\Support\LegacyInvoiceImporter;
use RuntimeException;

/**
 * Uploads an invoices CSV (an export from a previous system, the same shape
 * this screen's own export already prints) and imports it via {@see
 * InvoiceImporter}. Hostinger-safe direct POST; manager-gated.
 */
final class LimoInvoiceImportController
{
    public function __invoke(Request $request, InvoiceImporter $importer): RedirectResponse
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canApproveMaintenance(), 403);

        $validated = $request->validate(['file' => ['required', 'file', 'max:16384']]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['file'];

        // The old limousine system's own invoices list (Invoice # / Booking
        // Ref / Amount (BHD) …) has its own importer, which keeps the old
        // invoice numbers and links each to its booking. Recognise it by its
        // headings so both files go through the one button.
        if ($this->isOldSystemRegister($file->getRealPath())) {
            try {
                $result = app(LegacyInvoiceImporter::class)->import($file->getRealPath());
            } catch (RuntimeException $e) {
                return redirect('/app/limousine/invoice')->with('toast', $e->getMessage());
            }

            return redirect('/app/limousine/invoice')->with('toast', __(':imported invoices imported, :skipped already on file skipped.', [
                'imported' => $result['imported'], 'skipped' => $result['skipped'],
            ]));
        }

        $result = $importer->import($file->getRealPath());

        return redirect('/app/limousine/invoice')->with('toast', __(
            ':imported invoices imported, :skipped already on file skipped.',
            ['imported' => $result['imported'], 'skipped' => $result['skipped']],
        ));
    }

    private function isOldSystemRegister(string $path): bool
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return false;
        }
        $header = fgetcsv($handle);
        fclose($handle);
        if ($header === false) {
            return false;
        }

        $names = array_map(
            static fn ($h): string => strtolower(trim((string) preg_replace('/^\x{FEFF}/u', '', (string) $h))),
            $header,
        );

        return in_array('invoice #', $names, true) && in_array('booking ref', $names, true);
    }
}
