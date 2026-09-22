<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Limousine\Support\LegacyReceiptImporter;
use Modules\Limousine\Support\ReceiptImporter;
use RuntimeException;

/**
 * Uploads a receipts CSV and imports it. Takes both this screen's own export
 * ({@see ReceiptImporter}, which keeps the RCP/ number, the confirmed state
 * and who raised it) and the old limousine system's receipts register
 * ({@see LegacyReceiptImporter}, which keeps the L-RCPT number and links by
 * booking) — told apart by their headings. Hostinger-safe direct POST;
 * manager-gated.
 */
final class LimoReceiptImportController
{
    public function __invoke(Request $request, ReceiptImporter $importer): RedirectResponse
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canApproveMaintenance(), 403);

        $validated = $request->validate(['file' => ['required', 'file', 'max:16384']]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['file'];

        if ($this->isOldSystemRegister($file->getRealPath())) {
            try {
                $result = app(LegacyReceiptImporter::class)->import($file->getRealPath());
            } catch (RuntimeException $e) {
                return redirect('/app/limousine/receipt')->with('toast', $e->getMessage());
            }
        } else {
            $result = $importer->import($file->getRealPath());
        }

        return redirect('/app/limousine/receipt')->with('toast', __(
            ':imported receipts imported, :skipped already on file skipped.',
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

        return in_array('rcpt no.', $names, true) && in_array('booking #', $names, true);
    }
}
