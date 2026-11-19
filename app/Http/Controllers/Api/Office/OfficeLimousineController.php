<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Office;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoExpense;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoLeg;
use Modules\Rental\Models\Vehicle;

/**
 * The limousine section as the AI office's Limousine Manager reads it: trips, invoices
 * and expenses. Names, dates, amounts and states only — never a customer's phone,
 * email or ID number, a signature or a payment link.
 */
final class OfficeLimousineController
{
    use ReadsOfficeLists;

    private const STATUSES = ['queue', 'confirmed', 'active', 'completed', 'cancelled'];

    /** Every trip (one leg of a booking) by its start day. */
    public function trips(Request $request): JsonResponse
    {
        if (! Schema::hasTable('limo_legs')) {
            return self::notInstalled();
        }

        $query = LimoLeg::query()->where('legable_type', LimoBooking::class);
        $error = $this->filterDates($request, $query, 'start_at');
        if ($error !== null) {
            return $error;
        }

        $status = $request->query('status');
        if ($status !== null) {
            if (! in_array($status, self::STATUSES, true)) {
                return self::invalid('status must be one of '.implode(', ', self::STATUSES).'.');
            }
            $query->where('status', $status);
        }

        $bookings = LimoBooking::query()->with('customer:id,name')->get(['id', 'reference', 'customer_id', 'booking_type', 'payment_status'])->keyBy('id');
        /** @var array<int, string> $drivers */
        $drivers = LimoDriver::query()->pluck('name', 'id')->all();
        /** @var array<int, string> $cars */
        $cars = Vehicle::query()->get(['id', 'name', 'plate_no'])->mapWithKeys(static fn (Vehicle $car): array => [$car->id => trim($car->name.' '.$car->plate_no)])->all();

        return $this->page($request, $query, static function (LimoLeg $leg) use ($bookings, $drivers, $cars): array {
            $booking = $bookings->get((int) $leg->getAttribute('legable_id'));
            $carId = $leg->getAttribute('car_id');
            $driverId = $leg->getAttribute('driver_id');
            $startAt = $leg->getAttribute('start_at');

            return [
                'id' => $leg->id,
                'reference' => $leg->getAttribute('reference'),
                'booking' => $booking?->getAttribute('reference'),
                'booking_type' => $booking?->getAttribute('booking_type'),
                'customer' => $booking?->getRelation('customer')?->getAttribute('name'),
                'service_type' => $leg->getAttribute('service_type'),
                'from' => $leg->getAttribute('from_location'),
                'to' => $leg->getAttribute('to_location'),
                'start_at' => $startAt instanceof Carbon ? $startAt->format('Y-m-d H:i') : null,
                'hours' => $leg->getAttribute('hours'),
                'days' => $leg->getAttribute('days'),
                'status' => $leg->getAttribute('status'),
                'car' => $carId !== null ? ($cars[(int) $carId] ?? null) : $leg->getAttribute('vehicle'),
                'driver' => $driverId !== null ? ($drivers[(int) $driverId] ?? null) : $leg->getAttribute('driver'),
                'line_total' => (float) $leg->getAttribute('line_total'),
                'net_amount' => (float) $leg->getAttribute('net_amount'),
                'driver_payment' => $leg->getAttribute('driver_payment') !== null ? (float) $leg->getAttribute('driver_payment') : null,
                'outside_company' => $leg->getAttribute('outside_company'),
                'outside_cost' => $leg->getAttribute('outside_cost') !== null ? (float) $leg->getAttribute('outside_cost') : null,
                'cancellation_reason' => $leg->getAttribute('cancellation_reason'),
                'refund_amount' => $leg->getAttribute('refund_amount') !== null ? (float) $leg->getAttribute('refund_amount') : null,
                'booking_payment' => $booking?->getAttribute('payment_status'),
            ];
        });
    }

    /** Invoices by issue day, with what is still owed and how late it is. */
    public function invoices(Request $request): JsonResponse
    {
        if (! Schema::hasTable('limo_invoices')) {
            return self::notInstalled();
        }

        $query = LimoInvoice::query()->with(['customer:id,name', 'booking:id,reference']);
        $error = $this->filterDates($request, $query, 'issue_date');
        if ($error !== null) {
            return $error;
        }

        $status = $request->query('status');
        if ($status !== null) {
            if (! in_array($status, ['unpaid', 'partial', 'paid'], true)) {
                return self::invalid('status must be unpaid, partial or paid.');
            }
            $query->where('status', $status);
        }

        $today = Carbon::today();

        return $this->page($request, $query, static function (LimoInvoice $invoice) use ($today): array {
            $total = (float) $invoice->getAttribute('total');
            $paid = (float) $invoice->getAttribute('amount_paid');
            $due = $invoice->getAttribute('due_date');
            $issued = $invoice->getAttribute('issue_date');
            $open = $invoice->getAttribute('status') !== 'paid';

            return [
                'id' => $invoice->id,
                'reference' => $invoice->getAttribute('reference'),
                'customer' => $invoice->getRelation('customer')?->getAttribute('name'),
                'booking' => $invoice->getRelation('booking')?->getAttribute('reference'),
                'issue_date' => $issued instanceof Carbon ? $issued->format('Y-m-d') : null,
                'due_date' => $due instanceof Carbon ? $due->format('Y-m-d') : null,
                'total' => round($total, 3),
                'paid' => round($paid, 3),
                'balance' => round($total - $paid, 3),
                'status' => $invoice->getAttribute('status'),
                'days_overdue' => $open && $due instanceof Carbon && $due->lt($today) ? (int) $due->diffInDays($today) : 0,
            ];
        });
    }

    /** Section expenses by day: fuel, tolls, outside cars… with the driver they belong to. */
    public function expenses(Request $request): JsonResponse
    {
        if (! Schema::hasTable('limo_expenses')) {
            return self::notInstalled();
        }

        $query = LimoExpense::query();
        $error = $this->filterDates($request, $query, 'date');
        if ($error !== null) {
            return $error;
        }

        /** @var array<int, string> $drivers */
        $drivers = LimoDriver::query()->pluck('name', 'id')->all();

        return $this->page($request, $query, static function (LimoExpense $expense) use ($drivers): array {
            $date = $expense->getAttribute('date');
            $driverId = $expense->getAttribute('driver_id');

            return [
                'id' => $expense->id,
                'reference' => $expense->getAttribute('reference'),
                'date' => $date instanceof Carbon ? $date->format('Y-m-d') : null,
                'category' => $expense->getAttribute('category'),
                'amount' => (float) $expense->getAttribute('amount'),
                'net_amount' => $expense->getAttribute('net_amount') !== null ? (float) $expense->getAttribute('net_amount') : null,
                'vat_amount' => $expense->getAttribute('vat_amount') !== null ? (float) $expense->getAttribute('vat_amount') : null,
                'payee' => $expense->getAttribute('payee'),
                'driver' => $driverId !== null ? ($drivers[(int) $driverId] ?? null) : null,
                'has_bill' => $expense->getAttribute('bill_path') !== null,
            ];
        });
    }

    private static function notInstalled(): JsonResponse
    {
        return response()->json(['error' => 'Limousine is not installed in this workspace.'], 404);
    }
}
