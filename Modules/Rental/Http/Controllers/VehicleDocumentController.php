<?php

declare(strict_types=1);

namespace Modules\Rental\Http\Controllers;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Modules\Rental\Models\Vehicle;
use Modules\Rental\Models\VehicleDocument;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

/**
 * A car's papers by year: add one, open one, download a year (or all) as a ZIP.
 *
 * Uploads come straight here rather than through Livewire's temporary-upload
 * pipeline, which has never worked reliably on the host. Files are stored on
 * the private disk and only ever leave through these signed-in routes.
 * Deleting is not here: it is the owner's alone, on the car page.
 */
final class VehicleDocumentController extends Controller
{
    public function store(Request $request, int $vehicle): JsonResponse
    {
        $this->authorizeCars(Permission::Write);
        $car = Vehicle::query()->findOrFail($vehicle);

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,heic,heif', 'max:8192'],
            'year' => ['required', 'integer', 'min:2000', 'max:' . ((int) Carbon::now()->year + 5)],
            'kind' => ['required', Rule::in(array_keys(VehicleDocument::kinds()))],
            'title' => ['nullable', 'string', 'max:120'],
        ]);

        $file = $request->file('file');
        abort_if($file === null || is_array($file), 422);

        $path = $file->store(VehicleDocument::FOLDER . '/' . $car->id, VehicleDocument::DISK);
        abort_if($path === false, 500);

        $user = Auth::user();

        $document = VehicleDocument::query()->create([
            'vehicle_id' => $car->id,
            'year' => (int) $data['year'],
            'kind' => (string) $data['kind'],
            'title' => isset($data['title']) && trim((string) $data['title']) !== '' ? trim((string) $data['title']) : null,
            'original_name' => $file->getClientOriginalName(),
            'disk' => VehicleDocument::DISK,
            'path' => $path,
            'size' => (int) $file->getSize(),
            'uploaded_by' => $user instanceof User ? (string) $user->name : null,
        ]);

        return response()->json(['id' => $document->id]);
    }

    public function show(int $document): StreamedResponse
    {
        $this->authorizeCars(Permission::Read);
        $doc = VehicleDocument::query()->findOrFail($document);
        abort_unless($doc->fileExists(), 404);

        // Shown in the browser (a PDF or a photo), under its own name.
        return Storage::disk($doc->disk)->response($doc->path, $doc->downloadName(), [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Every paper of one year (`?year=`), or all of them, in one ZIP. */
    public function zip(Request $request, int $vehicle): BinaryFileResponse
    {
        $this->authorizeCars(Permission::Read);
        $car = Vehicle::query()->findOrFail($vehicle);

        $year = (int) $request->query('year', '0');
        $documents = VehicleDocument::query()
            ->where('vehicle_id', $car->id)
            ->when($year > 0, static fn ($q) => $q->where('year', $year))
            ->orderBy('year')->orderBy('kind')->orderBy('id')
            ->get();

        abort_if($documents->isEmpty(), 404);

        $tmp = tempnam(sys_get_temp_dir(), 'car-papers-');
        abort_if($tmp === false, 500);

        $zip = new ZipArchive();
        abort_unless($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 500);

        $used = [];
        foreach ($documents as $doc) {
            if (! $doc->fileExists()) {
                continue;
            }

            // <year>/<kind> - <file name>; a number is added if two collide.
            $name = $doc->year . '/' . $doc->kind . ' - ' . $doc->downloadName();
            if (isset($used[$name])) {
                $name = $doc->year . '/' . $doc->kind . ' - ' . $doc->id . ' - ' . $doc->downloadName();
            }
            $used[$name] = true;

            $zip->addFromString($name, (string) Storage::disk($doc->disk)->get($doc->path));
        }
        $zip->close();

        $plate = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($car->plate_no ?: $car->id)), '-');
        $file = 'car-' . $plate . '-papers' . ($year > 0 ? '-' . $year : '') . '.zip';

        return response()->download($tmp, $file)->deleteFileAfterSend(true);
    }

    private function authorizeCars(Permission $permission): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'rental.vehicle', $permission);
    }
}
