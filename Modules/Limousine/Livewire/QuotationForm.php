<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use App\Livewire\Concerns\ScrollsToFirstError;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
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
    public const VALIDITY_WEEK = 'week';

    public const VALIDITY_MONTH = 'month';

    public const VALIDITY_YEAR = 'year';

    public const VALIDITY_CUSTOM = 'custom';

    use GuardsModelAccess;
    use ScrollsToFirstError;

    protected function accessModelKey(): string
    {
        return 'limousine.quotation';
    }

    use HandlesTripLegs;

    /** The record being edited — server-set only; the browser must not repoint it. */
    #[Locked]
    public ?int $id = null;

    public string $reference = '';

    public string $quote_date = '';

    public ?int $customer_id = null;

    public string $contact_person = '';

    public string $requested_by = '';

    /**
     * Who raised this quotation — stamped from the signed-in user, never typed.
     *
     * The same rule as the booking form, and for the same reason: this is a
     * sign-off, so it must not depend on what arrived from the browser.
     * #[Locked] because Livewire lets the client set any unlocked property, and
     * an existing quote keeps whoever actually raised it rather than being
     * re-stamped with whoever opened it next.
     */
    #[Locked]
    public string $prepared_by = '';

    public string $contact_number = '';

    public string $valid_until = '';

    /**
     * How long the quote stands, as a choice rather than a date to work out.
     *
     * "Valid for a month" is what the office decides; 07-Oct-2026 is only what
     * that comes to. So the periods are the buttons and the date follows them,
     * with `custom` for the times a customer asks for a particular day.
     *
     * Not stored — the DATE is the record. This only says how it was arrived at,
     * which is why re-opening a quote works it back out from the date.
     */
    public string $validity = self::VALIDITY_WEEK;

    public string $notes = '';

    public string $status = LimoQuotation::STATUS_DRAFT;

    #[Locked]
    public ?int $booking_id = null;

    /** Inline "New customer" modal (shared transport customer). */
    public bool $addingCustomer = false;

    /** @var array<string, string> */
    public array $newCustomer = ['name' => '', 'phone' => '', 'email' => '', 'type' => 'individual'];

    public function mount(int|string|null $id = null): void
    {
        // A route segment is always a string, and a non-numeric one
        // ("new") means a new record rather than a bad request.
        $id = is_numeric($id) ? (int) $id : null;

        $this->guardAccess(Permission::Read);
        if ($id !== null) {
            $quote = LimoQuotation::query()->with('legs')->find($id);
            if ($quote !== null) {
                $this->id = $quote->id;
                $this->reference = $quote->reference ?? '';
                $this->quote_date = $quote->quote_date?->format('Y-m-d') ?? '';
                $this->customer_id = $quote->customer_id;
                $this->contact_person = $quote->contact_person ?? '';
                $this->requested_by = $quote->requested_by ?? '';
                // Keep whoever actually raised it; only fill in when the quote
                // predates the stamp, since the field can no longer be typed.
                $this->prepared_by = trim((string) $quote->prepared_by) !== ''
                    ? (string) $quote->prepared_by
                    : $this->currentUserName();
                $this->contact_number = $quote->contact_number ?? '';
                $this->valid_until = $quote->valid_until?->format('Y-m-d') ?? '';
                $this->notes = $quote->notes ?? '';
                $this->status = $quote->status;
                $this->booking_id = $quote->booking_id;
                $this->validity = $this->validityFromDates();
                $this->loadLegs($quote);

                return;
            }
        }

        $this->quote_date = now()->format('Y-m-d');
        $this->valid_until = now()->addWeek()->format('Y-m-d');
        $this->prepared_by = $this->currentUserName();
        $this->seedLegs();
    }

    /**
     * Choose how long the quote stands, and set the date to match.
     *
     * Measured from the QUOTE's date, not today: a quote dated last week that
     * is good for a month runs out a month after it was written, not a month
     * after somebody happened to open it.
     */
    public function setValidity(string $period): void
    {
        if ($period === self::VALIDITY_CUSTOM) {
            $this->validity = self::VALIDITY_CUSTOM;

            return;
        }

        if (! in_array($period, [self::VALIDITY_WEEK, self::VALIDITY_MONTH, self::VALIDITY_YEAR], true)) {
            return;
        }

        $this->validity = $period;
        $this->valid_until = $this->addPeriod($this->validFrom(), $period)->format('Y-m-d');
    }

    /** A period re-measures itself when the quote's own date moves. */
    public function updatedQuoteDate(): void
    {
        if ($this->validity !== self::VALIDITY_CUSTOM) {
            $this->setValidity($this->validity);
        }
    }

    /** Typing a date by hand is the custom case, by definition. */
    public function updatedValidUntil(): void
    {
        $this->validity = self::VALIDITY_CUSTOM;
    }

    /**
     * One period on, without rolling over the end of a month.
     *
     * A plain "+1 month" from the 31st of August lands on the 1st of October,
     * because the 31st of September does not exist — so a quote written on the
     * 31st would claim a day more than the month it was given. The 30th is what
     * "a month" means here.
     */
    private function addPeriod(Carbon $from, string $period): Carbon
    {
        return match ($period) {
            self::VALIDITY_MONTH => $from->addMonthNoOverflow(),
            self::VALIDITY_YEAR => $from->addYearNoOverflow(),
            default => $from->addWeek(),
        };
    }

    private function validFrom(): Carbon
    {
        return $this->parseDate($this->quote_date) ?? Carbon::now();
    }

    /**
     * Work out which period an existing quote was written with, so re-opening
     * it shows the button that was pressed rather than always saying custom.
     */
    private function validityFromDates(): string
    {
        if ($this->quote_date === '' || $this->valid_until === '') {
            return self::VALIDITY_CUSTOM;
        }

        $from = $this->parseDate($this->quote_date);
        if ($from === null) {
            return self::VALIDITY_CUSTOM;
        }
        $until = $this->valid_until;

        foreach ([self::VALIDITY_WEEK, self::VALIDITY_MONTH, self::VALIDITY_YEAR] as $period) {
            if ($this->addPeriod($from->copy(), $period)->format('Y-m-d') === $until) {
                return $period;
            }
        }

        return self::VALIDITY_CUSTOM;
    }

    /**
     * A typed date as Carbon, or null while it is empty or not a date yet.
     *
     * Read on every render, before validation has had its say, so a half-typed
     * value must not take the page down the way one once did on the chauffeur
     * schedule.
     */
    private function parseDate(string $value): ?Carbon
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (InvalidFormatException) {
            return null;
        }
    }

    /**
     * Display name for the signed-in user, for the "Prepared by" stamp.
     *
     * Falls back to the email because staff accounts can be username-only, and
     * an empty string would trip the `required` rule on a field nobody can type
     * into.
     */
    private function currentUserName(): string
    {
        $user = Auth::user();
        if ($user === null) {
            return '';
        }

        $name = trim((string) ($user->name ?? ''));

        return $name !== '' ? $name : trim((string) ($user->email ?? ''));
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
        $this->guardSave($this->id === null);
        $this->validateFocusing();

        $quote = $this->id !== null ? LimoQuotation::query()->find($this->id) : new LimoQuotation();
        if ($quote === null) {
            return;
        }

        $first = $this->legs[0] ?? $this->emptyLeg();

        $quote->quote_date = $this->quote_date !== '' ? Carbon::parse($this->quote_date) : null;
        $quote->customer_id = $this->customer_id;
        $quote->contact_person = $this->trimOrNull($this->contact_person);
        $quote->requested_by = $this->trimOrNull($this->requested_by);
        $quote->prepared_by = $this->trimOrNull($this->prepared_by) ?? $this->currentUserName();
        $quote->contact_number = $this->trimOrNull($this->contact_number);
        $quote->valid_until = $this->valid_until !== '' ? Carbon::parse($this->valid_until) : null;
        $quote->notes = $this->trimOrNull($this->notes);
        // Derive the header trip basics from the first leg so convert-to-booking works.
        $quote->pickup_at = ($first['start_at'] ?? '') !== '' ? Carbon::parse($first['start_at']) : Carbon::now();
        $quote->car_type = null; // the car now lives on each leg
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
        $this->guardAccess(Permission::Write);
        $this->validateFocusing([
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
        $this->guardAccess(Permission::Write);
        $this->setStatus(LimoQuotation::STATUS_SENT);
    }

    public function markAccepted(): void
    {
        $this->guardAccess(Permission::Write);
        $this->setStatus(LimoQuotation::STATUS_ACCEPTED);
    }

    public function markDeclined(): void
    {
        $this->guardAccess(Permission::Write);
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
        $this->guardAccess(Permission::Write);
        if ($this->id === null) {
            return;
        }

        $quote = LimoQuotation::query()->find($this->id);
        if ($quote === null) {
            return;
        }

        $invoice = $quote->convertToInvoice();
        session()->flash('toast', __('Invoice raised from this quotation.'));
        $this->redirect('/app/limousine/invoice', navigate: true);
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
            // See BookingForm::render() — an already-selected customer must
            // stay visible even if it was deactivated after this quotation
            // was raised.
            'customers' => LimoCustomer::activeOrSelected($this->customer_id, ['id', 'name', 'phone']),
            'isEditing' => $this->id !== null,
            'validUntilLabel' => $this->parseDate($this->valid_until)?->isoFormat('DD-MMM-YYYY') ?? '—',
            ...$this->legViewData(),
        ]);
    }
}
