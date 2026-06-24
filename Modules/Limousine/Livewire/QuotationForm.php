<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLocation;
use Modules\Limousine\Models\LimoQuotation;

/**
 * Bespoke limousine quotation form with status actions and "Convert to
 * booking" (spawns a queued booking and jumps to it).
 */
#[Layout('components.layouts.app')]
#[Title('Quotation')]
final class QuotationForm extends Component
{
    public ?int $id = null;

    public ?int $customer_id = null;

    public ?int $pickup_location_id = null;

    public ?int $dropoff_location_id = null;

    public string $pickup_at = '';

    public string $valid_until = '';

    public string $car_type = 'sedan';

    public string $fare = '0';

    public string $notes = '';

    public string $reference = '';

    public string $status = LimoQuotation::STATUS_DRAFT;

    public ?int $booking_id = null;

    public function mount(?int $id = null): void
    {
        if ($id !== null) {
            $quote = LimoQuotation::query()->find($id);
            if ($quote !== null) {
                $this->id = $quote->id;
                $this->customer_id = $quote->customer_id;
                $this->pickup_location_id = $quote->pickup_location_id;
                $this->dropoff_location_id = $quote->dropoff_location_id;
                $this->pickup_at = $quote->pickup_at?->format('Y-m-d\TH:i') ?? '';
                $this->valid_until = $quote->valid_until?->format('Y-m-d') ?? '';
                $this->car_type = $quote->car_type ?? 'sedan';
                $this->fare = (string) $quote->fare;
                $this->notes = $quote->notes ?? '';
                $this->reference = $quote->reference ?? '';
                $this->status = $quote->status;
                $this->booking_id = $quote->booking_id;

                return;
            }
        }

        $this->pickup_at = now()->addHour()->format('Y-m-d\TH:i');
        $this->valid_until = now()->addWeek()->format('Y-m-d');
    }

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer'],
            'pickup_location_id' => ['nullable', 'integer'],
            'dropoff_location_id' => ['nullable', 'integer'],
            'pickup_at' => ['required', 'date'],
            'valid_until' => ['nullable', 'date'],
            'car_type' => ['nullable', 'string'],
            'fare' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function save(): void
    {
        $this->validate();

        $quote = $this->id !== null ? LimoQuotation::query()->find($this->id) : new LimoQuotation();
        if ($quote === null) {
            return;
        }

        $quote->customer_id = $this->customer_id;
        $quote->pickup_location_id = $this->pickup_location_id;
        $quote->dropoff_location_id = $this->dropoff_location_id;
        $quote->pickup_at = Carbon::parse($this->pickup_at);
        $quote->valid_until = $this->valid_until !== '' ? Carbon::parse($this->valid_until) : null;
        $quote->car_type = $this->car_type !== '' ? $this->car_type : null;
        $quote->fare = (float) $this->fare;
        $quote->notes = $this->notes !== '' ? $this->notes : null;
        $quote->save();

        session()->flash('toast', __('Quotation saved.'));
        $this->redirect('/app/limousine/quotation', navigate: true);
    }

    public function markSent(): void
    {
        $this->setStatus(LimoQuotation::STATUS_SENT);
    }

    public function markAccepted(): void
    {
        $this->setStatus(LimoQuotation::STATUS_ACCEPTED);
    }

    public function markDeclined(): void
    {
        $this->setStatus(LimoQuotation::STATUS_DECLINED);
    }

    private function setStatus(string $status): void
    {
        $this->withQuote(function (LimoQuotation $q) use ($status): void {
            $q->status = $status;
            $q->save();
        });
    }

    public function convert(): void
    {
        if ($this->id === null) {
            return;
        }

        $quote = LimoQuotation::query()->find($this->id);
        if ($quote === null) {
            return;
        }

        $booking = $quote->convertToBooking();
        session()->flash('toast', __('Converted to booking.'));
        $this->redirect('/app/limousine/booking/' . $booking->id, navigate: true);
    }

    private function withQuote(Closure $fn): void
    {
        if ($this->id === null) {
            return;
        }

        $quote = LimoQuotation::query()->find($this->id);
        if ($quote === null) {
            return;
        }

        $fn($quote);
        $quote->refresh();
        $this->status = $quote->status;
        $this->booking_id = $quote->booking_id;
    }

    public function render(): View
    {
        return view('limousine::quotation-form', [
            'customers' => LimoCustomer::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'phone']),
            'locations' => LimoLocation::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'carTypes' => LimoBooking::carTypeOptions(),
            'isEditing' => $this->id !== null,
        ]);
    }
}
