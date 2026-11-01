<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Admin\StaffRole;
use App\Erp\Admin\UserProvisioner;
use App\Erp\Tenancy\WorkspaceManager;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoLeg;
use Modules\Rental\Models\Vehicle;
use RuntimeException;

/**
 * A made-up business for the App Store and Google Play reviewers. Both stores
 * need a sign-in that shows the whole app, and a reviewer must never see a real
 * customer — so the reviewer's account is locked to this database alone, which
 * holds only invented customers, cars, drivers and trips.
 *
 * The trips are dated from "now" and put back the way they started on every
 * refresh (routes/console.php, every few hours): a reviewer days later still
 * finds trips waiting for a car and driver, and one who assigned a crew has not
 * spoilt the demo for the next. Nothing is ever deleted — refreshing rewrites
 * the same five trips.
 *
 *   php artisan staff-app:review-demo            create it, or refresh it
 *   php artisan staff-app:review-demo --refresh  refresh only (no-op without one)
 */
final class AppReviewDemo
{
    public const NAME = 'App Review Demo';

    public const SLUG = 'app-review-demo';

    public const LOGIN = 'appreview@wanaan-bh.com';

    /** @var list<string> */
    private const CUSTOMERS = ['Demo Travel Agency', 'Sample Hotel Front Desk', 'Example Corporate Account', 'Test Guest'];

    /**
     * reference => [hours from now, customer, pick-up, drop-off, crew (which
     * demo car and driver; null = none yet)]
     *
     * @var array<string, array{int, int, string, string, int|null}>
     */
    private const TRIPS = [
        'DEMO-101' => [2, 0, 'Bahrain International Airport', 'Demo Hotel, Manama', null],
        'DEMO-102' => [5, 1, 'Demo Hotel, Manama', 'Example Mall, Seef', null],
        'DEMO-103' => [8, 2, 'Example Office Tower, Seef', 'Bahrain International Airport', 0],
        'DEMO-104' => [20, 3, 'Sample Residence, Juffair', 'Demo Beach Resort', null],
        'DEMO-105' => [30, 0, 'Bahrain International Airport', 'Sample Residence, Juffair', 1],
    ];

    public function __construct(
        private readonly WorkspaceManager $workspaces,
        private readonly UserProvisioner $users,
    ) {
    }

    public function workspace(): ?Workspace
    {
        return $this->workspaces->withMain(
            static fn (): ?Workspace => Workspace::query()->where('slug', self::SLUG)->first(),
        );
    }

    /** Whether the reviewer's login exists (on Main, where logins live). */
    public function reviewerExists(): bool
    {
        return $this->workspaces->withMain(
            static fn (): bool => User::query()->where('email', self::LOGIN)->exists(),
        );
    }

    /**
     * Create the demo business (once) with the reviewer's account in it, and
     * put its trips back to the start.
     *
     * @param  string|null  $password  the reviewer's password: kept as it is when null
     */
    public function ensure(?string $password): Workspace
    {
        $workspace = $this->workspace() ?? $this->create();

        // A supervisor (view, add and edit — the reviewer must be able to
        // assign), Limousine only, locked to the demo — with the trip details
        // closed until a car and driver are on, and the trip reminders, so
        // the reviewer sees both.
        $this->users->provisionLocked('App Reviewer', self::LOGIN, $password, $workspace->id, StaffRole::Supervisor, ['limousine']);
        $this->inside($workspace, static function (): void {
            User::query()->where('email', self::LOGIN)->first()
                ?->forceFill(['trip_details_locked' => true, 'trip_reminders' => true])->save();
        });

        $this->refresh($workspace);

        return $workspace;
    }

    /** Date the five trips from now, each with its starting crew (or none). */
    public function refresh(Workspace $workspace, ?Carbon $now = null): void
    {
        $now ??= Carbon::now();

        $this->inside($workspace, function () use ($now): void {
            $cars = [$this->car('Demo Sedan', 'DEMO 1'), $this->car('Demo Van', 'DEMO 2')];
            $drivers = [$this->driver('Demo Driver A'), $this->driver('Demo Driver B')];
            $customers = array_map(
                static fn (string $name): LimoCustomer => LimoCustomer::query()->firstOrCreate(['name' => $name]),
                self::CUSTOMERS,
            );

            foreach (self::TRIPS as $reference => [$hours, $customer, $from, $to, $crew]) {
                $startAt = $now->copy()->addHours($hours)->startOfHour();

                $booking = LimoBooking::query()->firstOrNew(['reference' => 'DEMO/' . substr($reference, 5)]);
                $booking->forceFill([
                    'customer_id' => $customers[$customer]->id,
                    'pickup_at' => $startAt,
                    'status' => LimoBooking::STATUS_CONFIRMED,
                    'pax_name' => 'Demo Passenger',
                    'pax_contact' => '+973 0000 0000',
                ])->save();

                $leg = LimoLeg::query()->firstOrNew(['reference' => $reference]);
                $leg->forceFill([
                    'legable_type' => LimoBooking::class,
                    'legable_id' => $booking->id,
                    'sequence' => 0,
                    'status' => LimoLeg::STATUS_CONFIRMED,
                    'start_at' => $startAt,
                    'from_location' => $from,
                    'from_country' => 'BH',
                    'to_location' => $to,
                    'to_country' => 'BH',
                    'rate' => 20,
                    'net_amount' => 20,
                    'car_id' => $crew === null ? null : $cars[$crew]->id,
                    'driver_id' => $crew === null ? null : $drivers[$crew]->id,
                    'push_reminded_at' => null,
                    'push_escalated_at' => null,
                ])->save();
            }
        });
    }

    private function create(): Workspace
    {
        $owner = $this->workspaces->withMain(
            static fn (): ?User => User::query()->where('is_super_admin', true)->orderBy('id')->first()
                ?? User::query()->where('is_admin', true)->orderBy('id')->first(),
        );
        if ($owner === null) {
            throw new RuntimeException('There is no admin on Main to own the demo business.');
        }

        return $this->workspaces->provision(self::NAME, $owner, ['limousine']);
    }

    private function car(string $name, string $plate): Vehicle
    {
        $car = Vehicle::query()->firstOrNew(['plate_no' => $plate]);
        $car->forceFill(['name' => $name, 'status' => Vehicle::STATUS_AVAILABLE, 'active' => true])->save();

        return $car;
    }

    private function driver(string $name): LimoDriver
    {
        $driver = LimoDriver::query()->firstOrNew(['name' => $name]);
        $driver->forceFill(['active' => true])->save();

        return $driver;
    }

    /** @param  \Closure(): void  $callback */
    private function inside(Workspace $workspace, \Closure $callback): void
    {
        $path = $workspace->databasePath();
        if ($path === null || ! is_file($path)) {
            throw new RuntimeException('The demo business has no database file.');
        }

        $this->workspaces->withTenant($path, $callback);
    }
}
