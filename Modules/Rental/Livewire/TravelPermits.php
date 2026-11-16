<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use App\Erp\Security\Permission;
use App\Erp\Settings\Setting;
use App\Livewire\Concerns\GuardsModelAccess;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Rental\Models\Driver;
use Modules\Rental\Models\TravelPermit;
use Modules\Rental\Models\Vehicle;
use Modules\Rental\Services\TravelPermitIssuer;
use Modules\Rental\Services\TravelPermitPdf;

/**
 * Travel authorizations: write the letter that lets a driver take a company
 * car to another Gulf country, and see who holds one for which car.
 *
 * The car and the driver are picked from the records, never typed, and the
 * page says what is missing before a letter can be made. The PDF is the only
 * output. See {@see TravelPermitIssuer} for the rules.
 */
#[Layout('components.layouts.app')]
#[Title('Travel authorizations')]
final class TravelPermits extends Component
{
    use GuardsModelAccess;
    use WithPagination;

    public string $vehicle_id = '';

    public string $driver_id = '';

    public string $issued_on = '';

    public string $valid_from = '';

    public string $period = '7d';

    #[Url(except: '')]
    public string $search = '';

    /** Only the letters still in force or yet to start. */
    #[Url(except: false)]
    public bool $current = false;

    public string $ownerName = '';

    public string $ownerNo = '';

    protected function accessModelKey(): string
    {
        return 'rental.order';
    }

    public function mount(): void
    {
        $this->guardAccess(Permission::Read);

        $this->issued_on = Carbon::today()->toDateString();
        $this->valid_from = Carbon::today()->toDateString();
        $this->ownerName = TravelPermitIssuer::ownerName();
        $this->ownerNo = TravelPermitIssuer::ownerNo();

        request()->attributes->set('breadcrumb_terminal_label', __('Travel authorizations'));
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCurrent(): void
    {
        $this->resetPage();
    }

    public function setPeriod(string $period): void
    {
        if (in_array($period, TravelPermitIssuer::PERIODS, true)) {
            $this->period = $period;
        }
    }

    public function issue(TravelPermitIssuer $issuer): void
    {
        $this->guardAccess(Permission::Write);

        $this->validate([
            'vehicle_id' => ['required', 'integer'],
            'driver_id' => ['required', 'integer'],
            'issued_on' => ['required', 'date'],
            'valid_from' => ['required', 'date'],
            'period' => ['required', 'in:' . implode(',', TravelPermitIssuer::PERIODS)],
        ], [], [
            'vehicle_id' => __('Car'),
            'driver_id' => __('Driver'),
            'issued_on' => __('Date of the letter'),
            'valid_from' => __('Valid from'),
        ]);

        $car = Vehicle::query()->find((int) $this->vehicle_id);
        $driver = Driver::query()->find((int) $this->driver_id);

        if ($car === null || $driver === null) {
            throw ValidationException::withMessages([
                $car === null ? 'vehicle_id' : 'driver_id' => [__('Choose it from the list.')],
            ]);
        }

        $user = Auth::user();
        $letter = $issuer->issue(
            $car,
            $driver,
            Carbon::parse($this->issued_on),
            Carbon::parse($this->valid_from),
            $this->period,
            $user instanceof User ? (string) $user->name : null,
        );

        $this->vehicle_id = '';
        $this->driver_id = '';
        $this->resetPage();

        session()->flash('toast', $letter->shortened
            ? __('Authorization :ref made. It ends on :date, the last day of the car\'s registration.', ['ref' => (string) $letter->reference, 'date' => $letter->valid_to->format('d/m/Y')])
            : __('Authorization :ref made.', ['ref' => (string) $letter->reference]));
    }

    /** The owner's name and number printed on every new letter. Owner only. */
    public function saveOwner(): void
    {
        $this->guardOwner();

        $this->validate([
            'ownerName' => ['required', 'string', 'max:120'],
            'ownerNo' => ['required', 'string', 'max:40'],
        ], [], ['ownerName' => __('Owner name'), 'ownerNo' => __('Owner number')]);

        Setting::set(TravelPermitIssuer::OWNER_NAME, trim($this->ownerName));
        Setting::set(TravelPermitIssuer::OWNER_NO, trim($this->ownerNo));

        session()->flash('toast', __('Saved.'));
    }

    /** Allow or stop one account printing with the stamp. Owner only. */
    public function setStamper(int $userId, bool $allowed): void
    {
        $this->guardOwner();

        $user = User::query()->find($userId);
        if ($user === null || $user->isSuperAdmin()) {
            return; // the super admin always may; there is nothing to switch
        }

        $user->forceFill(['can_stamp_travel_permits' => $allowed])->save();
    }

    private function guardOwner(): void
    {
        $user = Auth::user();

        abort_unless($user instanceof User && $user->isSuperAdmin(), 403);
    }

    public function render(TravelPermitIssuer $issuer): View
    {
        $user = Auth::user();
        $isOwner = $user instanceof User && $user->isSuperAdmin();
        $canStamp = $user instanceof User && $user->canStampTravelPermits();

        // What picking this car and this driver would give, before saving.
        $car = $this->vehicle_id !== '' ? Vehicle::query()->find((int) $this->vehicle_id) : null;
        $driver = $this->driver_id !== '' ? Driver::query()->find((int) $this->driver_id) : null;
        $from = $this->parse($this->valid_from) ?? Carbon::today();

        $carProblems = $car !== null ? $issuer->carProblems($car, $from) : [];
        $driverProblems = $driver !== null ? $issuer->driverProblems($driver) : [];
        $window = $car !== null && $carProblems === [] ? $issuer->window($car, $from, $this->period) : null;
        $held = $car !== null && $driver !== null ? TravelPermit::inForce((int) $car->id, (int) $driver->id) : null;

        $like = '%' . trim($this->search) . '%';
        $letters = TravelPermit::query()
            ->when(trim($this->search) !== '', static function ($q) use ($like): void {
                $q->where(static function ($w) use ($like): void {
                    $w->where('driver_name', 'like', $like)
                        ->orWhere('driver_cpr', 'like', $like)
                        ->orWhere('plate_no', 'like', $like)
                        ->orWhere('reference', 'like', $like)
                        ->orWhere('make', 'like', $like)
                        ->orWhere('model', 'like', $like);
                });
            })
            ->when($this->current, static fn ($q) => $q->whereDate('valid_to', '>=', Carbon::today()))
            ->orderByDesc('id')
            ->paginate(25);

        return view('rental::travel-permits', [
            'cars' => Vehicle::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'plate_no']),
            'drivers' => Driver::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'cpr']),
            'periods' => TravelPermitIssuer::periodLabels(),
            'car' => $car,
            'driver' => $driver,
            'carProblems' => $carProblems,
            'driverProblems' => $driverProblems,
            'window' => $window,
            'held' => $held,
            'letters' => $letters,
            'canMake' => $this->mayAccess(Permission::Write),
            'canStamp' => $canStamp,
            'isOwner' => $isOwner,
            'hasStamp' => TravelPermitPdf::artworkPath('stamp') !== null,
            'hasSignature' => TravelPermitPdf::artworkPath('signature') !== null,
            'staff' => $isOwner
                ? User::query()->orderBy('name')->get(['id', 'name', 'email', 'is_super_admin', 'can_stamp_travel_permits'])
                : collect(),
        ]);
    }

    private function parse(string $date): ?Carbon
    {
        if (trim($date) === '') {
            return null;
        }

        try {
            return Carbon::parse($date);
        } catch (\Carbon\Exceptions\InvalidFormatException) {
            return null;
        }
    }
}
