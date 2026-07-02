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
use Modules\Limousine\Models\LimoQuotationLine;

/**
 * Bespoke limousine quotation form: a header plus unlimited priced line items
 * (add/remove lines), with status actions and "Convert to booking".
 */
#[Layout('components.layouts.app')]
#[Title('Quotation')]
final class QuotationForm extends Component
{
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

    /** @var list<array<string, string>> */
    public array $lines = [];

    /** Inline "New customer" modal (shared transport customer). */
    public bool $addingCustomer = false;

    /** @var array<string, string> */
    public array $newCustomer = ['name' => '', 'phone' => '', 'email' => '', 'type' => 'individual'];

    /**
     * @return array<string, string>
     */
    private function emptyLine(): array
    {
        return [
            'quote_type' => '', 'rate_type' => '', 'date_from' => '', 'date_to' => '',
            'hours' => '', 'units' => '1', 'vehicle' => '', 'vehicle_details' => '',
            'rate' => '0', 'discount' => '0', 'vat' => '0',
        ];
    }

    public function mount(?int $id = null): void
    {
        if ($id !== null) {
            $quote = LimoQuotation::query()->with('lines')->find($id);
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

                $this->lines = $quote->lines->map(fn (LimoQuotationLine $l): array => [
                    'quote_type' => $l->quote_type ?? '',
                    'rate_type' => $l->rate_type ?? '',
                    'date_from' => $l->date_from?->format('Y-m-d\TH:i') ?? '',
                    'date_to' => $l->date_to?->format('Y-m-d\TH:i') ?? '',
                    'hours' => $l->hours !== null ? (string) $l->hours : '',
                    'units' => (string) $l->units,
                    'vehicle' => $l->vehicle ?? '',
                    'vehicle_details' => $l->vehicle_details ?? '',
                    'rate' => (string) $l->rate,
                    'discount' => (string) $l->discount,
                    'vat' => (string) $l->vat,
                ])->all();

                if ($this->lines === []) {
                    $this->lines = [$this->emptyLine()];
                }

                return;
            }
        }

        $this->quote_date = now()->format('Y-m-d');
        $this->valid_until = now()->addWeek()->format('Y-m-d');
        $this->lines = [$this->emptyLine()];
    }

    public function addLine(): void
    {
        $this->lines[] = $this->emptyLine();
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
        if ($this->lines === []) {
            $this->lines = [$this->emptyLine()];
        }
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
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.quote_type' => ['required', 'string'],
            'lines.*.rate_type' => ['required', 'string'],
            'lines.*.date_from' => ['required', 'date'],
            'lines.*.date_to' => ['required', 'date'],
            'lines.*.hours' => ['nullable', 'numeric', 'min:0'],
            'lines.*.units' => ['required', 'integer', 'min:1'],
            'lines.*.vehicle' => ['required', 'string'],
            'lines.*.vehicle_details' => ['nullable', 'string', 'max:255'],
            'lines.*.rate' => ['required', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.vat' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function save(): void
    {
        $this->validate();

        $quote = $this->id !== null ? LimoQuotation::query()->find($this->id) : new LimoQuotation();
        if ($quote === null) {
            return;
        }

        $first = $this->lines[0] ?? $this->emptyLine();

        $quote->quote_date = $this->quote_date !== '' ? Carbon::parse($this->quote_date) : null;
        $quote->customer_id = $this->customer_id;
        $quote->contact_person = $this->trimOrNull($this->contact_person);
        $quote->requested_by = $this->trimOrNull($this->requested_by);
        $quote->prepared_by = $this->trimOrNull($this->prepared_by);
        $quote->contact_number = $this->trimOrNull($this->contact_number);
        $quote->valid_until = $this->valid_until !== '' ? Carbon::parse($this->valid_until) : null;
        $quote->notes = $this->trimOrNull($this->notes);
        // Derive the header trip basics from the first line so convert-to-booking works.
        $quote->pickup_at = $first['date_from'] !== '' ? Carbon::parse($first['date_from']) : Carbon::now();
        $quote->car_type = $first['vehicle'] !== '' ? $first['vehicle'] : null;
        $quote->fare = $this->grandTotal();
        $quote->save();

        // Replace the lines wholesale (simplest reliable sync for a quote).
        $quote->lines()->delete();
        foreach ($this->lines as $i => $line) {
            $rate = (float) ($line['rate'] === '' ? '0' : $line['rate']);
            $units = max(1, (int) ($line['units'] === '' ? '1' : $line['units']));
            $discount = (float) ($line['discount'] === '' ? '0' : $line['discount']);
            $vat = (float) ($line['vat'] === '' ? '0' : $line['vat']);

            $quote->lines()->create([
                'sequence' => $i,
                'quote_type' => $this->trimOrNull($line['quote_type']),
                'rate_type' => $this->trimOrNull($line['rate_type']),
                'date_from' => $line['date_from'] !== '' ? Carbon::parse($line['date_from']) : null,
                'date_to' => $line['date_to'] !== '' ? Carbon::parse($line['date_to']) : null,
                'hours' => $line['hours'] !== '' ? (float) $line['hours'] : null,
                'units' => $units,
                'vehicle' => $this->trimOrNull($line['vehicle']),
                'vehicle_details' => $this->trimOrNull($line['vehicle_details']),
                'rate' => $rate,
                'discount' => $discount,
                'vat' => $vat,
                'line_total' => LimoQuotationLine::grossFor($rate, $units),
                'net_amount' => LimoQuotationLine::netFor($rate, $units, $discount, $vat),
            ]);
        }

        session()->flash('toast', __('Quotation saved.'));
        $this->redirect('/app/limousine/quotation', navigate: true);
    }

    /** Grand total = sum of line nets (recomputed from the live line inputs). */
    public function grandTotal(): float
    {
        $sum = 0.0;
        foreach ($this->lines as $line) {
            $sum += LimoQuotationLine::netFor(
                (float) ($line['rate'] ?? 0),
                max(1, (int) ($line['units'] ?? 1)),
                (float) ($line['discount'] ?? 0),
                (float) ($line['vat'] ?? 0),
            );
        }

        return round($sum, 3);
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
            'locations' => LimoLocation::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'quoteTypes' => LimoBooking::bookingTypeOptions(),
            'rateTypes' => LimoBooking::rateTypeOptions(),
            'vehicleOptions' => [...LimoBooking::carTypeOptions(), ['value' => 'other', 'label' => 'Other']],
            'grandTotal' => $this->grandTotal(),
            'isEditing' => $this->id !== null,
        ]);
    }
}
