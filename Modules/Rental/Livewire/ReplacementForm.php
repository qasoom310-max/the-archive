<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalReplacement;
use Modules\Rental\Models\Vehicle;

/**
 * Bespoke car-replacement form. Saving a new one puts the replacement car on
 * the road and the original into maintenance; "Close" frees both.
 */
#[Layout('components.layouts.app')]
#[Title('Car replacement')]
final class ReplacementForm extends Component
{
    public ?int $id = null;

    public ?int $customer_id = null;

    public ?int $original_vehicle_id = null;

    public ?int $replacement_vehicle_id = null;

    public string $date = '';

    public string $reason = '';

    public string $notes = '';

    public string $reference = '';

    public string $status = RentalReplacement::STATUS_ACTIVE;

    public function mount(?int $id = null): void
    {
        if ($id !== null) {
            $replacement = RentalReplacement::query()->find($id);
            if ($replacement !== null) {
                $this->id = $replacement->id;
                $this->customer_id = $replacement->customer_id;
                $this->original_vehicle_id = $replacement->original_vehicle_id;
                $this->replacement_vehicle_id = $replacement->replacement_vehicle_id;
                $this->date = $replacement->date?->format('Y-m-d') ?? '';
                $this->reason = $replacement->reason ?? '';
                $this->notes = $replacement->notes ?? '';
                $this->reference = $replacement->reference ?? '';
                $this->status = $replacement->status;

                return;
            }
        }

        $this->date = now()->format('Y-m-d');
    }

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer'],
            'original_vehicle_id' => ['required', 'integer'],
            'replacement_vehicle_id' => ['required', 'integer', 'different:original_vehicle_id'],
            'date' => ['required', 'date'],
            'reason' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function save(): void
    {
        $this->validate();

        $isNew = $this->id === null;
        $replacement = $isNew ? new RentalReplacement() : RentalReplacement::query()->find($this->id);
        if ($replacement === null) {
            return;
        }

        $replacement->customer_id = $this->customer_id;
        $replacement->original_vehicle_id = $this->original_vehicle_id;
        $replacement->replacement_vehicle_id = $this->replacement_vehicle_id;
        $replacement->date = Carbon::parse($this->date);
        $replacement->reason = $this->reason !== '' ? $this->reason : null;
        $replacement->notes = $this->notes !== '' ? $this->notes : null;
        $replacement->save();

        // A fresh, active replacement swaps the cars' availability.
        if ($isNew && $replacement->status === RentalReplacement::STATUS_ACTIVE) {
            $replacement->applyStatuses();
        }

        session()->flash('toast', __('Replacement saved.'));
        $this->redirect('/app/rental/replacement', navigate: true);
    }

    public function close(): void
    {
        if ($this->id === null) {
            return;
        }

        $replacement = RentalReplacement::query()->find($this->id);
        if ($replacement === null) {
            return;
        }

        $replacement->close();
        $this->status = $replacement->status;
    }

    public function render(): View
    {
        return view('rental::replacement-form', [
            'customers' => RentalCustomer::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'phone']),
            'vehicles' => Vehicle::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'plate_no', 'color', 'status']),
            'isEditing' => $this->id !== null,
        ]);
    }
}
