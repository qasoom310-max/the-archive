<?php

declare(strict_types=1);

namespace Modules\Rental\Services;

use App\Erp\Settings\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Modules\Rental\Models\TravelPermit;

/**
 * The travel authorization as a PDF, laid out like the traffic directorate's
 * own form. With `$stamped` the company stamp and the owner's signature are
 * placed beside "owner's signature"; without, that space is left empty to be
 * signed and stamped by hand.
 *
 * The stamp and signature are kept on the private disk and are read into the
 * PDF as data, so there is never a web address that serves them to the public.
 */
final class TravelPermitPdf
{
    public const DISK = 'local';

    public const FOLDER = 'travel_permit_artwork';

    public const KINDS = ['stamp', 'signature'];

    public static function settingKey(string $kind): string
    {
        return 'rental.permit.' . $kind;
    }

    /** Where the stamp or signature file is, or null when none was uploaded. */
    public static function artworkPath(string $kind): ?string
    {
        $path = trim((string) Setting::get(self::settingKey($kind), ''));

        return $path !== '' && Storage::disk(self::DISK)->exists($path) ? $path : null;
    }

    public function render(TravelPermit $permit, bool $stamped): \Barryvdh\DomPDF\PDF
    {
        return Pdf::loadView('rental::travel-permit-pdf', [
            'permit' => $permit,
            'stamp' => $stamped ? $this->dataUri('stamp') : null,
            'signature' => $stamped ? $this->dataUri('signature') : null,
        ])->setPaper('a4');
    }

    private function dataUri(string $kind): ?string
    {
        $path = self::artworkPath($kind);
        if ($path === null) {
            return null;
        }

        $bytes = Storage::disk(self::DISK)->get($path);
        if ($bytes === null || $bytes === '') {
            return null;
        }

        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }
}
