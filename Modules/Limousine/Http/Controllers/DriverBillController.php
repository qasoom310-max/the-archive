<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use App\Erp\Tenancy\WorkspaceManager;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Limousine\Models\LimoDriverBill;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Services\BillScanner;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The driver's own bill upload, from the link sent with his trip.
 *
 * Public: the driver has no account. The signed address is the credential
 * (trip + driver + 48-hour expiry), checked here rather than by middleware so
 * an expired link shows a plain "ask the office" page instead of an error. The
 * trip must still be his: a link stops working when the trip is given to
 * another driver. Each photo is read by the bill reader when possible and
 * waits for the office; the driver never creates an expense himself.
 */
final class DriverBillController
{
    public function show(int $leg, int $driver, Request $request): Response
    {
        return $this->inBusiness($request, function () use ($leg, $driver, $request): Response {
            $trip = $this->trip($leg, $driver, $request);
            if ($trip === null) {
                return response(view('limousine::driver-bills-upload', ['expired' => true])->render(), 410);
            }

            return response(view('limousine::driver-bills-upload', [
                'expired' => false,
                'trip' => $trip,
                'driverName' => (string) ($trip->driver ?? ''),
                'sent' => LimoDriverBill::query()->where('leg_id', $trip->id)->where('driver_id', $driver)->count(),
                'max' => LimoDriverBill::MAX_PER_LINK,
                'uploadUrl' => $request->fullUrl(),
            ])->render());
        });
    }

    public function upload(int $leg, int $driver, Request $request, BillScanner $scanner): JsonResponse
    {
        return $this->inBusiness($request, function () use ($leg, $driver, $request, $scanner): JsonResponse {
            $trip = $this->trip($leg, $driver, $request);
            if ($trip === null) {
                return response()->json(['message' => __('This link has expired. Ask the office for a new one.')], 410);
            }

            $sent = LimoDriverBill::query()->where('leg_id', $trip->id)->where('driver_id', $driver)->count();
            if ($sent >= LimoDriverBill::MAX_PER_LINK) {
                return response()->json(['message' => __('This link has taken all the bills it can. Send the rest to the office.')], 422);
            }

            $request->validate(['file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:12288']]);
            $file = $request->file('file');
            abort_if($file === null || is_array($file), 422);

            $path = $file->store(BillScanner::FOLDER . '/drivers', BillScanner::DISK);
            abort_if($path === false, 500);

            // Read it now when the reader is set up; a bill that cannot be read
            // is still kept, for the office to type in from the photo.
            $draft = null;
            if (BillScanner::isAvailable()) {
                try {
                    $read = $scanner->scan((string) $file->get(), (string) $file->getMimeType());
                    $draft = $read['readable'] ? $read : null;
                } catch (Throwable $e) {
                    Log::warning('Driver bill read failed: ' . $e->getMessage());
                }
            }

            LimoDriverBill::query()->create([
                'driver_id' => $driver,
                'leg_id' => $trip->id,
                'photo_path' => $path,
                'draft' => $draft,
            ]);

            return response()->json(['sent' => $sent + 1, 'max' => LimoDriverBill::MAX_PER_LINK]);
        });
    }

    /** The photo, for the office (signed in, Read on expenses). */
    public function photo(int $bill): StreamedResponse
    {
        app(AccessControl::class)->authorize(Auth::user(), 'limousine.expense', Permission::Read);

        $model = LimoDriverBill::query()->findOrFail($bill);
        abort_unless(Storage::disk(BillScanner::DISK)->exists($model->photo_path), 404);

        return Storage::disk(BillScanner::DISK)->response($model->photo_path);
    }

    /**
     * The trip, if the link is genuine, not expired, and the trip is still
     * this driver's and not cancelled.
     */
    private function trip(int $leg, int $driver, Request $request): ?LimoLeg
    {
        if (! $request->hasValidSignature()) {
            return null;
        }

        $trip = LimoLeg::query()->find($leg);

        return $trip !== null && (int) $trip->driver_id === $driver && $trip->status !== LimoLeg::STATUS_CANCELLED
            ? $trip
            : null;
    }

    /**
     * @template T
     * @param  Closure(): T  $callback
     * @return T
     */
    private function inBusiness(Request $request, Closure $callback): mixed
    {
        $ws = $request->query('ws');

        return app(WorkspaceManager::class)->runFor(is_string($ws) && ctype_digit($ws) ? (int) $ws : null, $callback);
    }
}
