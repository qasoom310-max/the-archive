<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers\App;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Services\AppTrips;
use Modules\Limousine\Services\LimoQueueRows;
use Modules\Limousine\Services\TripCrew;

/**
 * The staff app's trips: the lists operations work from, one trip in full,
 * and putting a car and a driver on it — under the same permission as the
 * Bookings list (limousine.booking: Read to look, Write to assign) and the
 * same rules (TripCrew), running in the person's own business database.
 */
final class TripController
{
    public function __construct(
        private readonly LimoQueueRows $rows,
        private readonly AppTrips $trips,
        private readonly TripCrew $crew,
    ) {
    }

    /**
     * ?scope=needs_crew (default) — waiting for a car or a driver;
     * today — every trip today; upcoming — the next seven days.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize(Permission::Read);

        $today = now()->toDateString();
        [$tab, $from, $to] = match ((string) $request->query('scope', 'needs_crew')) {
            'today' => [LimoQueueRows::TAB_ALL, $today, $today],
            'upcoming' => [LimoQueueRows::TAB_ALL, $today, now()->addDays(7)->toDateString()],
            default => [LimoQueueRows::TAB_NEEDS_CREW, '', ''],
        };

        $legs = $this->rows->query($tab, $from, $to, '', 'from_date', 'asc')->limit(200)->get();

        return response()->json([
            'data' => $legs->map(fn (LimoLeg $leg): array => $this->trips->present($leg))->values()->all(),
        ]);
    }

    public function show(int $leg): JsonResponse
    {
        $this->authorize(Permission::Read);

        return response()->json(['data' => $this->trips->present($this->trip($leg), detail: true)]);
    }

    public function crewOptions(int $leg): JsonResponse
    {
        $this->authorize(Permission::Write);
        $trip = $this->trip($leg);

        return response()->json([
            'cars' => $this->crew->carOptions($trip->car_id),
            'drivers' => $this->crew->driverOptions($trip->driver_id),
        ]);
    }

    public function assign(Request $request, int $leg): JsonResponse
    {
        $this->authorize(Permission::Write);

        $data = $request->validate([
            'car_id' => ['nullable', 'integer'],
            'driver_id' => ['nullable', 'integer'],
            'driver_payment' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        $trip = $this->trip($leg);

        // A finished or cancelled trip is a record of what happened, as on the
        // Bookings list.
        if (! in_array($trip->status, LimoLeg::OPEN_STATUSES, true)) {
            return response()->json(['message' => __('That trip is closed — its car and driver can no longer be changed.')], 422);
        }

        $result = $this->crew->assign(
            $trip,
            isset($data['car_id']) ? (int) $data['car_id'] : null,
            isset($data['driver_id']) ? (int) $data['driver_id'] : null,
            isset($data['driver_payment']) ? (string) $data['driver_payment'] : '',
        );

        if ($result['errors'] !== []) {
            $fields = ['car' => 'car_id', 'driver' => 'driver_id', 'pay' => 'driver_payment'];
            $errors = [];
            foreach ($result['errors'] as $field => $message) {
                $errors[$fields[$field]] = [$message];
            }

            return response()->json(['message' => reset($result['errors']), 'errors' => $errors], 422);
        }

        return response()->json([
            'data' => $this->trips->present($this->trip($leg), detail: true),
            'started' => $result['started'],
        ]);
    }

    private function authorize(Permission $permission): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'limousine.booking', $permission);
    }

    /** A booking's trip (never a quotation's), with what its row reads. */
    private function trip(int $id): LimoLeg
    {
        return LimoLeg::query()
            ->whereMorphedTo('legable', LimoBooking::class)
            ->with('legable.customer')
            ->findOrFail($id);
    }
}
