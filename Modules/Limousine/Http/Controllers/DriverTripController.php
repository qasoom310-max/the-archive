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
use Illuminate\Support\Facades\Storage;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoTripRun;
use Modules\Limousine\Services\DriverTrip;
use Modules\Limousine\Services\LimoQueueRows;
use Modules\Limousine\Support\DriverTripLink;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The driver's trip page, from the link the office copies to him.
 *
 * Public: the driver has no account. The signed address (trip + driver, no
 * expiry) is the credential, checked here so a wrong link shows a plain page.
 * The trip must still be his and not cancelled. Every action is a POST to the
 * same signed address with an `action` field, so the signature covers it.
 */
final class DriverTripController
{
    public function show(int $leg, int $driver, Request $request, DriverTrip $trips): Response
    {
        return $this->inBusiness($request, function () use ($leg, $driver, $request, $trips): Response {
            $trip = $this->trip($leg, $driver, $request);
            if ($trip === null || ! DriverTrip::ready()) {
                return response(view('limousine::driver-trip', ['invalid' => true])->render(), 410);
            }

            $run = LimoTripRun::query()->where('leg_id', $trip->id)->where('driver_id', $driver)->first();
            $blocking = ($run === null || ! $run->isStarted()) ? $trips->blocking($trip, $driver) : null;

            return response(view('limousine::driver-trip', [
                'invalid' => false,
                'trip' => $trip,
                'run' => $run,
                // The pick-up address, map, comments and money stay hidden until he
                // has recorded the start (the owner's rule: that is what makes him
                // do it). Before that: when, who, which countries, which car.
                'details' => $this->details($trip, $run?->isStarted() ?? false),
                'blocking' => $blocking,
                'blockingUrl' => $blocking?->leg !== null ? DriverTripLink::short($blocking->leg) : null,
                'postUrl' => $request->fullUrl(),
            ])->render());
        });
    }

    public function act(int $leg, int $driver, Request $request, DriverTrip $trips): JsonResponse
    {
        return $this->inBusiness($request, function () use ($leg, $driver, $request, $trips): JsonResponse {
            $trip = $this->trip($leg, $driver, $request);
            if ($trip === null || ! DriverTrip::ready()) {
                return response()->json(['message' => 'This link no longer works. Call the office. / هذا الرابط لم يعد يعمل. اتصل بالمكتب.'], 410);
            }

            $run = $trips->run($trip, $driver);
            $action = (string) $request->input('action');
            $stage = $run->isStarted() ? 'end' : 'start';

            if (! $run->isStarted() && $trips->blocking($trip, $driver) !== null) {
                return response()->json(['message' => 'Finish your other trip first. / أنهِ رحلتك الأخرى أولاً.'], 422);
            }
            if ($run->isEnded()) {
                return response()->json(['message' => 'This trip is finished. / هذه الرحلة انتهت.'], 422);
            }

            if ($action === 'photo') {
                $request->validate(['file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:15360']]);
                $file = $request->file('file');
                abort_if($file === null || is_array($file), 422);
                $path = $file->store(LimoTripRun::FOLDER, LimoTripRun::DISK);
                abort_if($path === false, 500);

                $read = $trips->photo($run, $stage, $path, (string) $file->get(), (string) $file->getMimeType());

                return response()->json(['stage' => $stage] + $read);
            }

            if ($action === 'start' || $action === 'end') {
                $data = $request->validate([
                    'km' => ['required', 'integer', 'min:1', 'max:9999999'],
                    'fuel' => ['required', 'integer', 'min:0', 'max:8'],
                    'lat' => ['nullable', 'numeric', 'between:-90,90'],
                    'lng' => ['nullable', 'numeric', 'between:-180,180'],
                    'accuracy' => ['nullable', 'numeric', 'min:0'],
                ]);
                $args = [
                    $trip, $run, (int) $data['km'], (int) $data['fuel'],
                    isset($data['lat']) ? (float) $data['lat'] : null,
                    isset($data['lng']) ? (float) $data['lng'] : null,
                    isset($data['accuracy']) ? (int) round((float) $data['accuracy']) : null,
                ];
                if ($action !== ($stage === 'start' ? 'start' : 'end')) {
                    return response()->json(['message' => 'Refresh the page. / حدّث الصفحة.'], 422);
                }
                $error = $action === 'start' ? $trips->start(...$args) : $trips->end(...$args);

                return $error === null ? response()->json(['ok' => true]) : response()->json(['message' => $error], 422);
            }

            return response()->json(['message' => 'Refresh the page. / حدّث الصفحة.'], 422);
        });
    }

    /** A dashboard photo, for the office (signed in, Read on bookings). */
    public function photo(int $run, string $stage): StreamedResponse
    {
        app(AccessControl::class)->authorize(Auth::user(), 'limousine.booking', Permission::Read);
        abort_unless(in_array($stage, ['start', 'end'], true), 404);

        $model = LimoTripRun::query()->findOrFail($run);
        $path = (string) $model->getAttribute($stage . '_photo');
        abort_unless($path !== '' && Storage::disk(LimoTripRun::DISK)->exists($path), 404);

        return Storage::disk(LimoTripRun::DISK)->response($path);
    }

    /**
     * What the driver needs for the trip, as label → value rows.
     *
     * @return list<array{label: string, value: string, url?: string, locked?: string}>
     */
    private function details(LimoLeg $leg, bool $started): array
    {
        $rows = app(LimoQueueRows::class);
        $booking = $rows->bookingOf($leg);
        $out = [];

        if ($leg->start_at !== null && $leg->service_type === LimoLeg::TYPE_CHAUFFEUR) {
            $out[] = ['label' => 'Time / الوقت', 'value' => implode(' · ', $rows->chauffeurWindow($leg))];
        } elseif ($leg->start_at !== null) {
            $out[] = ['label' => 'Date & time / التاريخ والوقت', 'value' => $leg->start_at->isoFormat('ddd DD-MMM-YY · HH:mm')];
        }
        $out[] = ['label' => 'Type / النوع', 'value' => $rows->tripType($leg, $booking)];

        // The passenger the driver meets: name and phone only (the owner's call).
        $pax = trim((string) ($booking->pax_name ?? ''));
        $paxPhone = trim((string) ($booking->pax_contact ?? ''));
        if ($pax === '' && $paxPhone === '') {
            $pax = trim((string) ($booking?->customer->name ?? ''));
            $paxPhone = trim((string) ($booking?->customer->phone ?? ''));
        }
        if ($pax !== '') {
            $out[] = ['label' => 'Passenger / الراكب', 'value' => $pax];
        }
        // The phone is locked with the address (option 1, 2026-11-24): with it
        // a driver could ask the passenger where to go and skip the start.
        if ($paxPhone !== '' && $started) {
            $out[] = ['label' => 'Phone / الهاتف', 'value' => $paxPhone, 'url' => 'tel:' . preg_replace('/[^0-9+]/', '', $paxPhone)];
        }
        if ($booking?->meet_greet) {
            $out[] = ['label' => 'Meet & greet / الاستقبال', 'value' => trim((string) ($booking->meet_greet_name ?? ''))];
        }

        foreach ([['Pick up / الاستلام', $leg->from_country, $leg->from_location, $leg->from_location_url], ['Drop off / التوصيل', $leg->to_country, $leg->to_location, $leg->to_location_url]] as [$label, $country, $place, $url]) {
            if (! $started) {
                $where = $this->country($country);
                if ($where !== '') {
                    $out[] = ['label' => $label, 'value' => $where];
                }

                continue;
            }
            $where = trim(implode(' — ', array_filter([$this->country($country), trim((string) $place)])));
            if ($where !== '') {
                $row = ['label' => $label, 'value' => $where];
                if (trim((string) $url) !== '') {
                    $row['url'] = trim((string) $url);
                }
                $out[] = $row;
            }
        }

        $car = trim(implode(' · ', array_filter([$rows->vehicleType($leg, $booking), trim((string) $leg->vehicle)])));
        if ($car !== '') {
            $out[] = ['label' => 'Car / السيارة', 'value' => $car];
        }

        if (! $started) {
            $out[] = ['label' => 'Address & phone / العنوان والهاتف', 'value' => '🔒 Shown after you press Start trip. / يظهران بعد الضغط على «بدء الرحلة».', 'locked' => '1'];

            return $out;
        }

        $notes = array_values(array_unique(array_filter([trim((string) ($booking->notes ?? '')), trim((string) ($leg->notes ?? ''))])));
        if ($notes !== []) {
            $out[] = ['label' => 'Comments / ملاحظات', 'value' => implode("\n", $notes)];
        }

        $state = $booking?->paymentState() ?? LimoBooking::PAYMENT_PAID;
        if ($state === LimoBooking::PAYMENT_PAID) {
            $out[] = ['label' => 'Payment / الدفع', 'value' => '✅ Paid / مدفوع'];
        } else {
            $value = $state === LimoBooking::PAYMENT_PARTIAL ? '🟡 Part paid / مدفوع جزئياً' : '❌ Unpaid / غير مدفوع';
            $customer = $booking?->customer;
            if ($customer === null || ! $customer->isCompany()) {
                $value .= "\nCollect / حصّل: " . number_format($booking?->amountOwed() ?? 0.0, 3) . ' BD';
            }
            $out[] = ['label' => 'Payment / الدفع', 'value' => $value];
        }

        return $out;
    }

    private function country(?string $code): string
    {
        return match (strtoupper(trim((string) $code))) {
            'BH' => 'Bahrain / البحرين',
            'SA' => 'Saudi Arabia / السعودية',
            'AE' => 'UAE / الإمارات',
            'QA' => 'Qatar / قطر',
            'KW' => 'Kuwait / الكويت',
            'OM' => 'Oman / عُمان',
            '' => '',
            default => strtoupper(trim((string) $code)),
        };
    }

    /** The trip, if the link is genuine and the trip is still this driver's and not cancelled. */
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
