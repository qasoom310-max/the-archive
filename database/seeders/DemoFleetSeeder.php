<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoLeg;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;

/**
 * Made-up cars, hires and limousine trips for the local DEMO copy only
 * (`scripts/demo-server.sh`), so the earnings pages have something to show
 * when a "What's new" picture is taken.
 *
 * Never part of the default seed chain and never run by a deploy. Every name
 * and number here is invented. The figures are fixed (no randomness), so two
 * pictures taken on different days show the same screen.
 */
final class DemoFleetSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('rental_vehicles') || ! Schema::hasTable('limo_legs')) {
            return;
        }

        if (Vehicle::query()->where('plate_no', '900001')->exists()) {
            return; // already seeded
        }

        $year = (int) Carbon::now()->year;

        // name, plate, yearly target
        $cars = [];
        foreach ([
            ['Demo Sedan A', '900001', 18000.0],
            ['Demo Sedan B', '900002', 18000.0],
            ['Demo SUV C', '900003', 24000.0],
            ['Demo Van D', '900004', 15000.0],
            ['Demo Luxury E', '900005', 30000.0],
            ['Demo Hatch F', '900006', 0.0],
        ] as [$name, $plate, $yearly]) {
            $cars[] = Vehicle::query()->create([
                'name' => $name,
                'plate_no' => $plate,
                'yearly_target' => $yearly,
                'monthly_target' => round($yearly / 12, 3),
                'daily_rate' => 20.0,
                'active' => true,
                'is_outside' => false,
            ]);
        }

        $renter = RentalCustomer::query()->firstOrCreate(['name' => 'Demo Renter Co.'], ['phone' => '30000001']);
        $rider = LimoCustomer::query()->firstOrCreate(['name' => 'Demo Travel Agency'], ['type' => 'company']);

        $drivers = [];
        foreach (['Demo Driver One', 'Demo Driver Two', 'Demo Driver Three', 'Demo Driver Four'] as $name) {
            $drivers[] = LimoDriver::query()->firstOrCreate(['name' => $name]);
        }

        $routes = [
            ['Demo Airport', 'Demo Hotel', 25.0],
            ['Demo Hotel', 'Demo Airport', 25.0],
            ['Demo City', 'Demo Border', 120.0],
            ['Demo City', 'Demo Capital', 280.0],
            ['Demo Mall', 'Demo Marina', 18.0],
        ];

        $reference = 90000;

        foreach ([$year - 1, $year] as $y) {
            $lastMonth = $y === $year ? (int) Carbon::now()->month : 12;

            for ($month = 1; $month <= $lastMonth; $month++) {
                foreach ($cars as $i => $car) {
                    // Hires: a different rhythm per car, quieter in some months.
                    if (($month + $i) % 4 !== 0) {
                        RentalOrder::query()->create([
                            'customer_id' => $renter->id,
                            'vehicle_id' => $car->id,
                            'start_date' => sprintf('%d-%02d-03', $y, $month),
                            'end_date' => sprintf('%d-%02d-%02d', $y, $month, 10 + $i * 2),
                            'total' => 420.0 + $i * 160.0 + ($month % 3) * 90.0,
                            'outside_cost' => 0.0,
                            'state' => RentalOrder::STATE_CLOSED,
                            'payment_status' => RentalOrder::PAYMENT_PAID,
                        ]);
                    }
                }

                // Limousine trips: most on a car from the fleet, some on none.
                for ($n = 0; $n < 14; $n++) {
                    [$from, $to, $fare] = $routes[($n + $month) % count($routes)];
                    $fare += ($n % 3) * 5.0;
                    $at = sprintf('%d-%02d-%02d %02d:15:00', $y, $month, 2 + $n * 2 % 26, 6 + ($n * 5) % 17);
                    $paid = ($n + $month) % 6 !== 0;
                    $driver = $drivers[($n + $month) % count($drivers)];
                    $car = $n % 4 === 3 ? null : $cars[($n + $month) % 5];

                    $booking = LimoBooking::query()->create([
                        'reference' => 'DEMO/' . $reference,
                        'customer_id' => $rider->id,
                        'pickup_at' => $at,
                        'fare' => $fare,
                        'amount' => $fare,
                        'advance' => $paid ? $fare : 0.0,
                        'status' => 'completed',
                        'payment_status' => $paid ? LimoBooking::PAYMENT_PAID : LimoBooking::PAYMENT_UNPAID,
                    ]);

                    LimoLeg::query()->create([
                        'legable_type' => LimoBooking::class,
                        'legable_id' => $booking->id,
                        'sequence' => 0,
                        'reference' => (string) $reference++,
                        'service_type' => $n % 5 === 0 ? 'chauffeur' : 'transfer',
                        'status' => 'completed',
                        'start_at' => $at,
                        'from_location' => $from,
                        'to_location' => $to,
                        'rate' => $fare,
                        'net_amount' => $fare,
                        'driver_id' => $driver->id,
                        'driver' => $driver->name,
                        'car_id' => $car?->id,
                        'vehicle' => $car?->name,
                    ]);
                }
            }
        }
    }
}
