<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Limousine\Livewire\Concerns\HandlesTripLegs;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoQuotation;

/**
 * Bespoke limousine quotation: a header plus unlimited trip legs (transfer /
 * chauffeur), with status actions and "Convert to booking".
 */
#[Layout('components.layouts.app')]
#[Title('Quotation')]
final class QuotationForm extends Component
{
    use HandlesTripLegs;

    public ?int $id = null;

    public string $reference = '';

    public string $quote_date = '';

    public ?int $customer_id = null;

    public string $contact_person = '';

    public string $requested_by = '';

    public string $prepared_by = '';

    public string $contact_number = '';

    public string $valid_until = '';

    public string $notes = '';

    public string $status = LimoQuotation::STATUS_DRAFT;

    public ?int $booking_id = null;

    /** Inline "New customer" modal (shared transport customer). */
    public bool $addingCustomer = false;

    /** @var array<string, string> */
    public array $newCustomer = ['name' => '', 'phone' => '', 'email' => '', 'type' => 'individual'];

    public function mount(?int $id = null): void
    {
        if ($id !== null) {
            $quote = LimoQuotation::query()->with('legs')->find($id);
            if ($quote !== null) {
                $this->id = $quote->id;
                $this->reference = $quote->reference ?? '';
                $this->quote_date = $quote->quote_date?->format('Y-m-d') ?? '';
                $this->customer_id = $quote->customer_id;
                $this->contact_person = $quote->contact_person ?? '';
                $this->requested_by = $quote->requested_by ?? '';
                $this->prepared_by = $quote->prepared_by ?? '';
                $this->contact_number = $quote->contact_number ?? '';
                $this->valid_until = $quote->valid_until?->format('Y-m-d') ?? '';
                $this->notes = $quote->notes ?? '';
                $this->status = $quote->status;
                $this->booking_id = $quote->booking_id;
                $this->loadLegs($quote);

                return;
            }
        }

        $this->quote_date = now()->format('Y-m-d');
        $this->valid_until = now()->addWeek()->format('Y-m-d');
        $this->seedLegs();
    }

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer'],
            'quote_date' => ['nullable', 'date'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'requested_by' => ['required', 'string', 'max:255'],
            'prepared_by' => ['required', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:100'],
            'valid_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            ...$this->legRules(),
        ];
    }

    public function save(): void
    {
        $this->validate();

        $quote = $this->id !== null ? LimoQuotation::query()->find($this->id) : new LimoQuotation();
        if ($quote === null) {
            return;
        }

        $first = $this->legs[0] ?? $this->emptyLeg();

        $quote->quote_date = $this->quote_date !== '' ? Carbon::parse($this->quote_date) : null;
        $quote->customer_id = $this->customer_id;
        $quote->contact_person = $this->trimOrNull($this->contact_person);
        $quote->requested_by = $this->trimOrNull($this->requested_by);
        $quote->prepared_by = $this->trimOrNull($this->prepared_by);
        $quote->contact_number = $this->trimOrNull($this->contact_number);
        $quote->valid_until = $this->valid_until !== '' ? Carbon::parse($this->valid_until) : null;
        $quote->notes = $this->trimOrNull($this->notes);
        // Derive the header trip basics from the first leg so convert-to-booking works.
        $quote->pickup_at = ($first['start_at'] ?? '') !== '' ? Carbon::parse($first['start_at']) : Carbon::now();
        $quote->car_type = ($first['vehicle'] ?? '') !== '' ? $first['vehicle'] : null;
        $quote->fare = $this->grandTotal();
        $quote->save();

        $this->persistLegs($quote);

        session()->flash('toast', __('Quotation saved.'));
        $this->redirect('/app/limousine/quotation', navigate: true);
    }

    private function trimOrNull(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    public function openCustomerModal(): void
    {
        $this->newCustomer = ['name' => '', 'phone' => '', 'email' => '', 'type' => 'individual'];
        $this->resetValidation();
        $this->addingCustomer = true;
    }

    public function closeCustomerModal(): void
    {
        $this->addingCustomer = false;
    }

    public function saveCustomer(): void
    {
        $this->validate([
            'newCustomer.name' => ['required', 'string', 'max:255'],
            'newCustomer.phone' => ['required', 'string', 'max:50'],
            'newCustomer.email' => ['required', 'email', 'max:255'],
            'newCustomer.type' => ['required', 'in:individual,company'],
        ]);

        $customer = LimoCustomer::query()->create([
            'name' => trim($this->newCustomer['name']),
            'type' => $this->newCustomer['type'],
            'phone' => trim($this->newCustomer['phone']),
            'email' => trim($this->newCustomer['email']),
        ]);

        $this->customer_id = $customer->id;
        $this->addingCustomer = false;
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
            'isEditing' => $this->id !== null,
            ...$this->legViewData(),
        ]);
    }
}
