<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Modules\Limousine\Support\BookingImporter;
use Modules\Limousine\Support\LegacyBookingImporter;
use RuntimeException;

/**
 * Uploads a trips CSV and imports it. One button takes both shapes:
 *
 * - the old limousine system's own booking lists ("#" / From / Customer /
 *   Amount …), via {@see LegacyBookingImporter}, which keeps the old booking
 *   numbers. Those lists carry no status per row, so the uploader says which
 *   list the file came from;
 * - this ERP's own queue export (and anything shaped like it), via
 *   {@see BookingImporter}, which keeps the trip numbers and reads each row's
 *   own status.
 *
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
            'list' => ['nullable', Rule::in(array_keys(LegacyBookingImporter::KIND_STATUS))],
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['file'];

        if ($this->isOldSystemList($file->getRealPath())) {
            $status = LegacyBookingImporter::KIND_STATUS[(string) ($validated['list'] ?? '')] ?? null;
            if ($status === null) {
                return redirect('/app/limousine/booking')->withErrors([
                    'list' => __('This file is from the old system: choose which list it came from, then import it again.'),
                ]);
            }

            try {
                $result = app(LegacyBookingImporter::class)->import($file->getRealPath(), $status);
            } catch (RuntimeException $e) {
                return redirect('/app/limousine/booking')->with('toast', $e->getMessage());
            }
        } else {
            $result = $importer->import($file->getRealPath());
        }

        return redirect('/app/limousine/booking')->with('toast', __(
            ':imported trips imported, :skipped already on file skipped.',
            ['imported' => $result['imported'], 'skipped' => $result['skipped']],
        ));
    }

    /** The old lists number bookings in a bare "#" column; this ERP prints "Reference". */
    private function isOldSystemList(string $path): bool
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
            static fn ($name): string => strtolower(trim((string) preg_replace('/^\x{FEFF}/u', '', (string) $name))),
            $header,
        );

        return in_array('#', $names, true) && ! in_array('reference', $names, true);
    }
}
