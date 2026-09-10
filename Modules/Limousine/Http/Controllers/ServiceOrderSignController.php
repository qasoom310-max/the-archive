<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Services\ServiceOrderPdf;

/**
 * The customer's side of a Service Order: open the emailed link, check the trip
 * details, sign, submit. That signature is the proof the driver reached them and
 * the service was used.
 *
 * PUBLIC on purpose — a customer has no account here. The URL carries Laravel's
 * signature, so it cannot be edited to reach another trip: change the leg id and
 * the signature no longer matches and the request is rejected before this
 * controller runs. Links are time-limited too, so an old mail can't be replayed
 * months later; the office simply resends.
 *
 * Signing is once-only. A second visit shows the completed order rather than
 * offering a fresh pad, so an existing proof can never be quietly overwritten.
 */
final class ServiceOrderSignController
{
    /** GET — show the trip and, if unsigned, the signature pad. */
    public function show(int $leg): View
    {
        $model = $this->leg($leg);

        return view('limousine::service-order-sign', $this->viewData($model));
    }

    /** POST — store the drawn signature. */
    public function store(int $leg, Request $request): RedirectResponse
    {
        $model = $this->leg($leg);

        // Already signed — never overwrite an existing proof.
        if ($model->isSigned()) {
            return redirect()->to($request->fullUrlWithoutQuery([]))->with(
                'service_order_status',
                __('This service order was already signed.'),
            );
        }

        $validated = $request->validate([
            'signed_name' => ['required', 'string', 'max:255'],
            // A PNG data URI produced by the canvas pad.
            'signature' => ['required', 'string', 'starts_with:data:image/png;base64,', 'max:2000000'],
        ]);

        $png = $this->decodePng((string) $validated['signature']);
        if ($png === null) {
            return back()->withErrors(['signature' => __('That signature could not be read. Please sign again.')]);
        }

        $path = 'limousine/service-orders/' . $model->id . '-' . Str::random(8) . '.png';
        Storage::disk('public')->put($path, $png);

        $model->forceFill([
            'signature_path' => $path,
            'signed_at' => Carbon::now(),
            'signed_name' => trim((string) $validated['signed_name']),
            // Recorded so a disputed trip can be answered with an origin, not
            // just an image.
            'signed_ip' => (string) $request->ip(),
        ])->save();

        return back()->with('service_order_status', __('Thank you — your signature has been recorded.'));
    }

    /** GET — the signed PDF, so the customer keeps their own copy. */
    public function pdf(int $leg, ServiceOrderPdf $pdf): \Illuminate\Http\Response
    {
        $model = $this->leg($leg);

        return response($pdf->render($model), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $pdf->filename($model) . '"',
        ]);
    }

    private function leg(int $leg): LimoLeg
    {
        return LimoLeg::query()->with('legable.customer')->findOrFail($leg);
    }

    /**
     * @return array<string, mixed>
     */
    private function viewData(LimoLeg $leg): array
    {
        $booking = $leg->legable instanceof LimoBooking ? $leg->legable : null;

        $customerName = '';
        if ($booking !== null) {
            $customer = $booking->customer;
            if ($customer !== null) {
                $customerName = (string) $customer->name;
            }
        }

        return [
            'leg' => $leg,
            'booking' => $booking,
            'customerName' => $customerName,
            'confirmationNo' => (string) ($leg->reference ?? ''),
            'serviceDate' => $leg->start_at?->isoFormat('DD-MMM-YYYY') ?? '',
            'serviceTime' => $leg->start_at?->isoFormat('hh:mm A') ?? '',
            'vehicle' => (string) ($leg->vehicle ?? ''),
            'driverName' => (string) ($leg->driver ?? ''),
            'pickup' => (string) ($leg->from_location ?? ''),
            'dropoff' => (string) ($leg->to_location ?? ''),
            'amount' => (float) $leg->net_amount,
            'signatureUrl' => $leg->signatureUrl(),
        ];
    }

    /**
     * Decode the canvas data URI, rejecting anything that isn't real PNG bytes
     * — the payload is public input, so its own claim of being an image is not
     * taken at face value.
     */
    private function decodePng(string $dataUri): ?string
    {
        $base64 = substr($dataUri, strlen('data:image/png;base64,'));
        $binary = base64_decode(strtr(trim($base64), ' ', '+'), true);

        if ($binary === false || $binary === '') {
            return null;
        }

        // PNG magic number.
        if (! str_starts_with($binary, "\x89PNG\r\n\x1a\n")) {
            return null;
        }

        return $binary;
    }
}
