<?php

declare(strict_types=1);

namespace Modules\Rental\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Rental\Models\RentalOrder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the orders report (current date range) as a CSV download. Mounted
 * inside the `auth` group, so only signed-in users reach it.
 */
final class RentalReportExportController
{
    public function __invoke(Request $request): StreamedResponse
    {
        $from = $request->query('from');
        $to = $request->query('to');
        $from = is_string($from) && $from !== '' ? $from : now()->startOfMonth()->format('Y-m-d');
        $to = is_string($to) && $to !== '' ? $to : now()->endOfMonth()->format('Y-m-d');

        $orders = RentalOrder::query()
            ->with(['customer:id,name', 'vehicle:id,name'])
            ->whereBetween('start_date', [$from, $to])
            ->orderBy('start_date')
            ->get();

        $filename = "rental-orders-{$from}_to_{$to}.csv";

        return response()->streamDownload(function () use ($orders): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fputcsv($out, ['Reference', 'Customer', 'Vehicle', 'Pick-up', 'Return', 'Rate type', 'Rate', 'Days', 'Total', 'Status', 'Payment']);

            foreach ($orders as $order) {
                fputcsv($out, [
                    $order->reference,
                    $order->customer?->name,
                    $order->vehicle?->name,
                    $order->start_date instanceof Carbon ? $order->start_date->format('Y-m-d') : '',
                    $order->end_date instanceof Carbon ? $order->end_date->format('Y-m-d') : '',
                    $order->rate_type,
                    $order->rate,
                    $order->days,
                    $order->total,
                    $order->state,
                    $order->payment_status,
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
