<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use App\Erp\Activity\ActivityLogger;
use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Rental\Models\Vehicle;
use Modules\Rental\Models\VehicleDocument;

/**
 * The "Papers by year" box on a car's page: every registration card,
 * insurance paper, invoice and agreement the car has had, under the year it
 * belongs to. Nothing is replaced when a new year is added, and nothing
 * leaves the list unless the owner deletes it.
 */
final class VehicleDocuments extends Component
{
    use GuardsModelAccess;

    #[Locked]
    public int $vehicleId;

    /** The year whose papers are listed. */
    public int $year = 0;

    protected function accessModelKey(): string
    {
        return 'rental.vehicle';
    }

    public function mount(int $vehicleId): void
    {
        $this->guardAccess(Permission::Read);
        $this->vehicleId = $vehicleId;

        // Open on the newest year that has papers, else this year.
        $latest = VehicleDocument::query()->where('vehicle_id', $vehicleId)->max('year');
        $this->year = $latest !== null ? (int) $latest : (int) Carbon::now()->year;
    }

    public function showYear(int $year): void
    {
        if ($year >= 2000 && $year <= (int) Carbon::now()->year + 5) {
            $this->year = $year;
        }
    }

    /** The upload posts straight to the controller, then asks for a refresh. */
    #[On('vehicle-document-added')]
    public function added(int $year): void
    {
        $this->showYear($year);
    }

    /** Deleting a car's paper is the owner's alone. */
    public function delete(int $documentId): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User && $user->isSuperAdmin(), 403);

        $document = VehicleDocument::query()->where('vehicle_id', $this->vehicleId)->find($documentId);
        if ($document === null) {
            return;
        }

        $car = Vehicle::query()->find($this->vehicleId);
        $what = $document->kindLabel() . ' ' . $document->year . ' (' . $document->original_name . ')';
        $document->remove();

        if ($car !== null) {
            app(ActivityLogger::class)->logFor($car, 'car_paper_deleted', $what);
        }
    }

    public function render(): View
    {
        $all = VehicleDocument::query()
            ->where('vehicle_id', $this->vehicleId)
            ->orderBy('kind')->orderByDesc('id')
            ->get();

        $thisYear = (int) Carbon::now()->year;
        $years = $all->pluck('year')->push($thisYear)->push($this->year)->unique()->sortDesc()->values()->all();
        $user = Auth::user();

        return view('rental::vehicle-documents', [
            'documents' => $all->where('year', $this->year)->values(),
            'counts' => $all->countBy('year')->all(),
            'years' => $years,
            'total' => $all->count(),
            'kinds' => VehicleDocument::kinds(),
            'uploadYears' => range($thisYear + 1, $thisYear - 10),
            'canAdd' => $this->mayAccess(Permission::Write),
            'canDelete' => $user instanceof User && $user->isSuperAdmin(),
        ]);
    }
}
