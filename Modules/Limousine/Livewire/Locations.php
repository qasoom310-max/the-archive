<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Activity\ActivityLogger;
use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Limousine\Models\LimoLocation;
use Modules\Limousine\Support\PickupPlaces;
use Modules\Limousine\Support\TripDetailsLock;

#[Layout('components.layouts.app')]
#[Title('Locations')]
final class Locations extends Component
{
    /** How many of the busiest pick-up places the box shows. */
    private const TOP_PLACES = 10;

    /** Bumped when a place is added, so the list under the box reloads. */
    public int $listVersion = 0;

    /**
     * The "Add" button beside a busy pick-up place that is not on the list:
     * save it as a location, under the country its trips were in.
     *
     * The place is looked up again by its key rather than trusted from the
     * browser, so only a place that trips really used can be added this way.
     */
    public function addPlace(string $key): void
    {
        abort_unless($this->allowed(Permission::Create), 403);
        abort_unless($this->maySeePlaces(), 403);

        foreach (app(PickupPlaces::class)->top(self::TOP_PLACES) as $place) {
            if ($place['key'] !== $key || $place['location_id'] !== null) {
                continue;
            }

            LimoLocation::query()->create(['name' => $place['name'], 'country' => $place['country']]);
            app(ActivityLogger::class)->log('created', __('Location'), $place['name'] . ' (' . $place['country'] . ')');
            $this->listVersion++;
            session()->flash('place_added', __(':name was added to the locations.', ['name' => $place['name']]));

            return;
        }
    }

    private function allowed(Permission $permission): bool
    {
        return app(AccessControl::class)->allows(Auth::user(), 'limousine.location', $permission);
    }

    /**
     * The box is built from trips, so it needs the list's own Read — and a
     * user who is kept from a trip's places until it has a crew does not get
     * them through this box either.
     */
    private function maySeePlaces(): bool
    {
        return $this->allowed(Permission::Read) && ! TripDetailsLock::appliesTo(Auth::user());
    }

    public function render(): View
    {
        $user = Auth::user();

        return view('limousine::locations', [
            'canManage' => $user instanceof User && $user->canApproveMaintenance(),
            'topPickups' => $this->maySeePlaces() ? app(PickupPlaces::class)->top(self::TOP_PLACES) : [],
            'canAdd' => $this->allowed(Permission::Create),
        ]);
    }
}
