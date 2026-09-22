<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Limousine\Support\LegacyQuotationImporter;
use Modules\Limousine\Support\QuotationImporter;
use RuntimeException;

/**
 * Uploads a quotations CSV (an export from a previous system, the same shape
 * this screen's own export already prints) and imports it via {@see
 * QuotationImporter}. Hostinger-safe direct POST; manager-gated.
 */
final class LimoQuotationImportController
{
    public function __invoke(Request $request, QuotationImporter $importer): RedirectResponse
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canApproveMaintenance(), 403);

        $validated = $request->validate(['file' => ['required', 'file', 'max:16384']]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['file'];

        // The old limousine system's own quotations list (Sl No. / # / Date /
        // Customer / Requested Person / Added By) is a different file with its
        // own importer — it keeps the old "QT/0563" numbers and who typed each
        // quote. Recognise it by its headings so both go through one button.
        if ($this->isOldSystemRegister($file->getRealPath())) {
            try {
                $result = app(LegacyQuotationImporter::class)->import($file->getRealPath());
            } catch (RuntimeException $e) {
                return redirect('/app/limousine/quotation')->with('toast', $e->getMessage());
            }
        } else {
            $result = $importer->import($file->getRealPath());
        }

        return redirect('/app/limousine/quotation')->with('toast', __(
            ':imported quotations imported, :skipped already on file skipped.',
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

        return in_array('#', $names, true) && in_array('requested person', $names, true);
    }
}
