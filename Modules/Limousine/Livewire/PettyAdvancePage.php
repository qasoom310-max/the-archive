<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Limousine\Models\LimoPettyAdvance;
use Modules\Limousine\Models\LimoPettyCategory;
use Modules\Rental\Models\Vehicle;
use Modules\Limousine\Services\PettyCash as PettyCashService;

/**
 * One advance, its whole life: the money out, the accountant's confirmation,
 * the paper receipts the driver handed back, and the settlement.
 *
 * The receipts are entered here line by line because that IS the control: a
 * lump "spent 84" proves nothing; five slips that add to 84, each with a
 * category and a photo, is something an owner can check.
 */
#[Layout('components.layouts.app')]
#[Title('Petty Cash')]
final class PettyAdvancePage extends Component
{
    use GuardsModelAccess;

    protected function accessModelKey(): string
    {
        return 'limousine.petty_cash';
    }

    /** The advance being worked — server-set; the browser must not repoint it. */
    #[Locked]
    public int $id = 0;

    public function mount(int $id): void
    {
        $this->guardAccess(Permission::Read);
        $this->id = $id;
        abort_unless(LimoPettyAdvance::query()->whereKey($id)->exists(), 404);
    }

    private function advance(): ?LimoPettyAdvance
    {
        return LimoPettyAdvance::query()->with(['driver', 'lines' => fn ($q) => $q->orderBy('date')->orderBy('id')])->find($this->id);
    }

    /** Confirming and settling are the accountant's, with super-admins. */
    private function guardConfirm(): void
    {
        abort_unless(Auth::user()?->canConfirmPayments() ?? false, 403);
    }

    /** New receipt line. */
    public string $lineDate = '';

    public string $lineCategory = 'Fuel';

    public string $lineDescription = '';

    public string $lineAmount = '';

    public string $linePhoto = '';

    /** Which car the money was spent on — optional; feeding a driver has none. */
    public string $lineCarId = '';

    public function addLine(): void
    {
        $this->guardAccess(Permission::Write);

        $advance = $this->advance();
        if ($advance === null || $advance->isCleared()) {
            return;
        }

        $this->validate([
            'lineDate' => ['required', 'date'],
            // Against the owner's list, not a hardcoded one.
            'lineCategory' => ['required', \Illuminate\Validation\Rule::exists('limo_petty_categories', 'name')],
            'lineDescription' => ['nullable', 'string', 'max:255'],
            'lineAmount' => ['required', 'numeric', 'min:0.001'],
            'linePhoto' => ['nullable', 'string', 'max:500'],
            'lineCarId' => ['nullable', 'integer'],
        ]);

        // Label snapshotted beside the ref, like the queue's legs: the slip
        // keeps saying which car even after the car is renamed or sold.
        $car = $this->lineCarId !== '' ? Vehicle::query()->find((int) $this->lineCarId) : null;

        $advance->lines()->create([
            'date' => $this->lineDate,
            'category' => $this->lineCategory,
            'description' => $this->lineDescription !== '' ? $this->lineDescription : null,
            'amount' => round((float) $this->lineAmount, 3),
            'photo_path' => $this->linePhoto !== '' ? $this->linePhoto : null,
            'car_id' => $car?->id,
            'vehicle' => $car?->displayName(),
        ]);

        $this->lineDescription = '';
        $this->lineAmount = '';
        $this->linePhoto = '';
        $this->lineCarId = '';
    }

    public function removeLine(int $lineId): void
    {
        $this->guardAccess(Permission::Write);

        $advance = $this->advance();
        if ($advance === null || $advance->isCleared()) {
            return;
        }

        $advance->lines()->whereKey($lineId)->delete();
    }

    public function confirm(PettyCashService $petty): void
    {
        $this->guardConfirm();

        $advance = $this->advance();
        if ($advance === null) {
            return;
        }

        $petty->confirm($advance, (string) (Auth::user()->name ?? ''));
        session()->flash('toast', __('Hand-over confirmed.'));
    }

    /** Settlement dialog — the outcome is shown before it is decided. */
    public bool $settling = false;

    public function openSettle(): void
    {
        $this->guardConfirm();
        $this->settling = true;
    }

    public function closeSettle(): void
    {
        $this->settling = false;
    }

    public function saveSettle(PettyCashService $petty): void
    {
        $this->guardConfirm();

        $advance = $this->advance();
        if ($advance === null) {
            return;
        }

        $result = $petty->settle($advance, (string) (Auth::user()->name ?? ''));
        $this->settling = false;

        if ($result === null) {
            return;
        }

        if ($result['shortfall'] > 0.0005) {
            session()->flash('toast', __(':amount to be deducted from :driver’s salary.', [
                'amount' => \App\Erp\Views\ValueFormat::money($result['shortfall']),
                'driver' => (string) ($advance->driver->name ?? ''),
            ]));
        } elseif ($result['excess'] > 0.0005) {
            session()->flash('toast', __('Pay :driver back :amount from the float.', [
                'amount' => \App\Erp\Views\ValueFormat::money($result['excess']),
                'driver' => (string) ($advance->driver->name ?? ''),
            ]));
        } else {
            session()->flash('toast', __('Cleared — receipts match the amount exactly.'));
        }
    }

    /** Inline "new category" box, open when set. */
    public bool $addingCategory = false;

    public string $newCategory = '';

    public function openCategory(): void
    {
        $this->guardCategory();
        $this->newCategory = '';
        $this->addingCategory = true;
    }

    public function closeCategory(): void
    {
        $this->addingCategory = false;
    }

    /** Growing the spending list is the owner's call, like the fee was. */
    private function guardCategory(): void
    {
        abort_unless(Auth::user()?->isAdmin() ?? false, 403);
    }

    public function saveCategory(): void
    {
        $this->guardCategory();

        $this->validate([
            'newCategory' => ['required', 'string', 'max:60', \Illuminate\Validation\Rule::unique('limo_petty_categories', 'name')],
        ]);

        // No expense slot: an invented category files under "other" in the
        // ledger, carrying its own name in the notes.
        LimoPettyCategory::query()->create(['name' => trim($this->newCategory)]);

        $this->lineCategory = trim($this->newCategory);
        $this->addingCategory = false;
    }

    public function render(): View
    {
        $advance = $this->advance();
        abort_if($advance === null, 404);

        $linesTotal = $advance->isCleared() ? $advance->receipts_total : $advance->linesTotal();
        $difference = round((float) $advance->amount - $linesTotal, 3);

        if ($this->lineDate === '') {
            $this->lineDate = now()->format('Y-m-d');
        }

        return view('limousine::petty-advance', [
            'advance' => $advance,
            'linesTotal' => $linesTotal,
            'difference' => $difference,
            'categories' => LimoPettyCategory::query()->orderBy('id')->pluck('name')->all(),
            'canAddCategory' => Auth::user()?->isAdmin() ?? false,
            'cars' => Vehicle::query()->orderBy('name')->get(['id', 'name', 'plate_no']),
            'canConfirm' => Auth::user()?->canConfirmPayments() ?? false,
            'canEdit' => ! $advance->isCleared() && $this->mayAccess(Permission::Write),
        ]);
    }
}
