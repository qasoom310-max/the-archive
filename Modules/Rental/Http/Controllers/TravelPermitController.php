<?php

declare(strict_types=1);

namespace Modules\Rental\Http\Controllers;

use App\Erp\Activity\ActivityLogger;
use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use App\Erp\Settings\Setting;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Modules\Rental\Models\TravelPermit;
use Modules\Rental\Services\TravelPermitPdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The travel authorization's PDF, and the stamp and signature that go on it.
 *
 * `?stamp=1` asks for the stamped letter and is refused to anyone the owner
 * has not allowed: a stamped letter is the company vouching for a driver at a
 * border. Every stamped download is written to the activity log.
 */
final class TravelPermitController extends Controller
{
    public function pdf(Request $request, int $permit, TravelPermitPdf $builder): Response
    {
        app(AccessControl::class)->authorize(Auth::user(), 'rental.order', Permission::Read);

        $letter = TravelPermit::query()->findOrFail($permit);
        $stamped = $request->boolean('stamp');

        if ($stamped) {
            $user = Auth::user();
            abort_unless($user instanceof User && $user->canStampTravelPermits(), 403);
            // Refuse rather than hand over a "stamped" letter with no stamp on it.
            abort_if(TravelPermitPdf::artworkPath('stamp') === null, 409, __('No stamp has been uploaded yet.'));

            app(ActivityLogger::class)->logFor($letter, 'travel_permit_stamped', $letter->driver_name . ' · ' . $letter->plate_no);
        }

        $file = 'authorization-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string) $letter->reference) . ($stamped ? '-stamped' : '') . '.pdf';

        return $builder->render($letter, $stamped)->download($file);
    }

    /** Upload the stamp or the signature. The owner's alone. */
    public function storeArtwork(Request $request): JsonResponse
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->isSuperAdmin(), 403);

        $data = $request->validate([
            'kind' => ['required', Rule::in(TravelPermitPdf::KINDS)],
            // No SVG: it can carry script. A scan or photo is what is wanted.
            'file' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
        ]);

        $file = $request->file('file');
        abort_if($file === null || is_array($file), 422);

        $path = $file->store(TravelPermitPdf::FOLDER, TravelPermitPdf::DISK);
        abort_if($path === false, 500);

        $key = TravelPermitPdf::settingKey((string) $data['kind']);
        $old = trim((string) Setting::get($key, ''));
        Setting::set($key, $path);

        // The replaced file is of no further use and should not linger.
        if ($old !== '' && $old !== $path) {
            Storage::disk(TravelPermitPdf::DISK)->delete($old);
        }

        return response()->json(['ok' => true]);
    }

    /** Show the stamp or signature to those allowed to use it. */
    public function artwork(string $kind): StreamedResponse
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canStampTravelPermits(), 403);

        $path = TravelPermitPdf::artworkPath($kind);
        abort_if($path === null, 404);

        return Storage::disk(TravelPermitPdf::DISK)->response($path, null, ['Cache-Control' => 'private, no-store']);
    }
}
