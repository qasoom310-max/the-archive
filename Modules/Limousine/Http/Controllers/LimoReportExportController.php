<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Limousine\Models\LimoBooking;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the bookings report (current date range) as a CSV download. Mounted
 * inside the `auth` group.
 */
final class LimoReportExportController
{
    public function __invoke(Request $request): StreamedResponse
    {
        $from = $request->query('from');
        $to = $request->query('to');
        $from = is_string($from) && $from !== '' ? $from : now()->startOfMonth()->format('Y-m-d');
        $to = is_string($to) && $to !== '' ? $to : now()->endOfMonth()->format('Y-m-d');

        $bookings = LimoBooking::query()
            ->with(['customer:id,name', 'pickupLocation:id,name', 'dropoffLocation:id,name'])
            ->whereBetween('pickup_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->orderBy('pickup_at')
            ->get();

        $filename = "limo-bookings-{$from}_to_{$to}.csv";

        return response()->streamDownload(function () use ($bookings): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fputcsv($out, ['Reference', 'Customer', 'Pickup', 'Dropoff', 'Pick-up time', 'Car type', 'Driver', 'Fare', 'Status', 'Payment']);

            foreach ($bookings as $b) {
                fputcsv($out, [
                    $b->reference,
                    $b->customer?->name,
                    $b->pickupLocation?->name,
                    $b->dropoffLocation?->name,
                    $b->pickup_at instanceof Carbon ? $b->pickup_at->format('Y-m-d H:i') : '',
                    $b->car_type,
                    $b->driver_name,
                    $b->fare,
                    $b->status,
                    $b->payment_status,
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
