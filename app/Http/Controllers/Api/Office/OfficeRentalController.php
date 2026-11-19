<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Office;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;

/**
 * The rent-a-car section as the AI office's Rent A Car Manager reads it: rentals and
 * the fleet. Names, dates, amounts and states only — never a customer's phone, ID
 * (CPR) or licence, their images, or the handover and return videos.
 */
final class OfficeRentalController
{
    use ReadsOfficeLists;

    private const STATES = ['draft', 'active', 'closed', 'cancelled'];

    /**
     * Rentals that overlap the asked days (`from`/`to`), so a rental running across the
     * period counts even if it started before it.
     */
    public function orders(Request $request): JsonResponse
    {
        if (! Schema::hasTable('rental_orders')) {
            return self::notInstalled();
        }

        $query = RentalOrder::query()->with(['customer:id,name', 'vehicle:id,name,plate_no', 'driver:id,name']);

        foreach (['from', 'to'] as $key) {
            $value = $request->query($key);
            if ($value === null) {
                continue;
            }
            if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
                return self::invalid($key.' must be a date written YYYY-MM-DD.');
            }
            // Overlap: ends on or after `from`, starts on or before `to`.
            $key === 'from'
                ? $query->where(static fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $value))
                : $query->whereDate('start_date', '<=', $value);
        }

        $state = $request->query('state');
        if ($state !== null) {
            if (! in_array($state, self::STATES, true)) {
                return self::invalid('state must be one of '.implode(', ', self::STATES).'.');
            }
            $query->where('state', $state);
        }

        /** @var array<int, string> $staff */
        $staff = User::query()->pluck('name', 'id')->all();
        $day = static fn (mixed $value): ?string => $value instanceof Carbon ? $value->format('Y-m-d') : null;
        $time = static fn (mixed $value): ?string => $value instanceof Carbon ? $value->format('Y-m-d H:i') : null;
        $money = static fn (mixed $value): ?float => $value !== null ? round((float) $value, 3) : null;

        return $this->page($request, $query, static function (RentalOrder $order) use ($staff, $day, $time, $money): array {
            $vehicle = $order->getRelation('vehicle');
            $createdBy = $order->getAttribute('created_by_user_id');

            return [
                'id' => $order->id,
                'reference' => $order->getAttribute('reference'),
                'customer' => $order->getRelation('customer')?->getAttribute('name'),
                'car' => $vehicle !== null ? trim($vehicle->getAttribute('name').' '.$vehicle->getAttribute('plate_no')) : null,
                'driver' => $order->getRelation('driver')?->getAttribute('name'),
                'order_date' => $day($order->getAttribute('order_date')),
                'start_date' => $day($order->getAttribute('start_date')),
                'end_date' => $day($order->getAttribute('end_date')),
                'started_at' => $time($order->getAttribute('started_at')),
                'returned_at' => $time($order->getAttribute('returned_at')),
                'state' => $order->getAttribute('state'),
                'rate_type' => $order->getAttribute('rate_type'),
                'rate' => $money($order->getAttribute('rate')),
                'days' => $order->getAttribute('days'),
                'discount' => $money($order->getAttribute('discount')),
                'total' => $money($order->getAttribute('total')),
                'balance' => $money($order->getAttribute('balance')),
                'payment_status' => $order->getAttribute('payment_status'),
                'payment_confirmed' => (bool) $order->getAttribute('payment_confirmed'),
                'deposit' => $money($order->getAttribute('deposit')),
                'deposit_status' => $order->getAttribute('deposit_status'),
                'deposit_deducted' => $money($order->getAttribute('deposit_deducted')),
                'deposit_reason' => $order->getAttribute('deposit_reason'),
                'pickup_km' => $order->getAttribute('handover_km'),
                'return_km' => $order->getAttribute('return_km'),
                'has_damage' => (bool) $order->getAttribute('has_damage'),
                'damage_notes' => $order->getAttribute('damage_notes'),
                'fuel_charge' => $money($order->getAttribute('fuel_charge')),
                'extra_charge' => $money($order->getAttribute('extra_charge')),
                'fines_amount' => $money($order->getAttribute('fines_amount')),
                'fines_checked' => $order->getAttribute('fines_checked_at') !== null,
                'created_by' => $createdBy !== null ? ($staff[(int) $createdBy] ?? null) : null,
            ];
        });
    }

    /** The fleet: every car, its state, prices, targets and what falls due (service, papers). */
    public function fleet(): JsonResponse
    {
        if (! Schema::hasTable('rental_vehicles')) {
            return self::notInstalled();
        }

        $day = static fn (mixed $value): ?string => $value instanceof Carbon ? $value->format('Y-m-d') : (is_string($value) && $value !== '' ? substr($value, 0, 10) : null);

        $cars = Vehicle::query()->orderBy('id')->get()->map(static fn (Vehicle $car): array => [
            'id' => $car->id,
            'name' => $car->name,
            'plate' => $car->getAttribute('plate_no'),
            'category' => $car->getAttribute('category'),
            'status' => $car->getAttribute('status'),
            'active' => (bool) $car->getAttribute('active'),
            'outside_car' => (bool) $car->getAttribute('is_outside'),
            'daily_rate' => $car->getAttribute('daily_rate') !== null ? (float) $car->getAttribute('daily_rate') : null,
            'monthly_rate' => $car->getAttribute('monthly_rate') !== null ? (float) $car->getAttribute('monthly_rate') : null,
            'monthly_target' => $car->getAttribute('monthly_target') !== null ? (float) $car->getAttribute('monthly_target') : null,
            'odometer' => $car->getAttribute('odometer'),
            'next_service_date' => $day($car->getAttribute('next_maintenance_date')),
            'next_service_km' => $car->getAttribute('next_maintenance_mileage'),
            'registration_expiry' => $day($car->getAttribute('registration_expiry')),
            'insurance_expiry' => $day($car->getAttribute('insurance_expiry')),
        ])->all();

        return response()->json(['data' => $cars]);
    }

    private static function notInstalled(): JsonResponse
    {
        return response()->json(['error' => 'Rent A Car is not installed in this workspace.'], 404);
    }
}
