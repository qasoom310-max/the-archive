<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use App\Livewire\Concerns\ScrollsToFirstError;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Services\BookingPayments;

/**
 * Bespoke limousine invoice form: the bill, its running balance + receipts,
 * taking money against it, and dispatching the trip it bills for.
 *
 * The invoice is the middle of the chain — quote → invoice → trip → receipt —
 * so both of those actions belong here rather than somewhere the office has to
 * go looking for.
 */
#[Layout('components.layouts.app')]
#[Title('Invoice')]
final class InvoiceForm extends Component
{
    use GuardsModelAccess;
    use ScrollsToFirstError;

    protected function accessModelKey(): string
    {
        return 'limousine.invoice';
    }

    /** The record being edited — server-set only; the browser must not repoint it. */
    #[Locked]
    public ?int $id = null;

    public ?int $customer_id = null;

    public string $issue_date = '';

    public string $due_date = '';

    public string $subtotal = '0';

    public string $discount = '0';

    public string $notes = '';

    public string $reference = '';

    public string $status = LimoInvoice::STATUS_UNPAID;

    public float $amount_paid = 0;

    public ?int $booking_id = null;

    public function mount(?int $id = null): void
    {
        $this->guardAccess(Permission::Read);
        if ($id !== null) {
            $invoice = LimoInvoice::query()->find($id);
            if ($invoice !== null) {
                $this->id = $invoice->id;
                $this->customer_id = $invoice->customer_id;
                $this->issue_date = $invoice->issue_date?->format('Y-m-d') ?? '';
                $this->due_date = $invoice->due_date?->format('Y-m-d') ?? '';
                $this->subtotal = (string) $invoice->subtotal;
                $this->discount = (string) $invoice->discount;
                $this->notes = $invoice->notes ?? '';
                $this->reference = $invoice->reference ?? '';
                $this->status = $invoice->status;
                $this->amount_paid = $invoice->amount_paid;
                $this->booking_id = $invoice->booking_id;

                return;
            }
        }

        $this->issue_date = now()->format('Y-m-d');
        $this->due_date = now()->addWeek()->format('Y-m-d');
    }

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ];
    }

    private function previewTotal(): float
    {
        $sub = (float) ($this->subtotal === '' ? '0' : $this->subtotal);
        $disc = (float) ($this->discount === '' ? '0' : $this->discount);

        return round(max(0.0, $sub - $disc), 3);
    }

    public function save(): void
    {
        $this->guardSave($this->id === null);
        $this->validateFocusing();

        $invoice = $this->id !== null ? LimoInvoice::query()->find($this->id) : new LimoInvoice();
        if ($invoice === null) {
            return;
        }

        $invoice->customer_id = $this->customer_id;
        $invoice->issue_date = Carbon::parse($this->issue_date);
        $invoice->due_date = $this->due_date !== '' ? Carbon::parse($this->due_date) : null;
        $invoice->subtotal = (float) $this->subtotal;
        $invoice->discount = (float) ($this->discount === '' ? '0' : $this->discount);
        $invoice->total = $this->previewTotal();
        $invoice->notes = $this->notes !== '' ? $this->notes : null;
        $invoice->save();
        $invoice->recomputePaid();

        session()->flash('toast', __('Invoice saved.'));
        $this->redirect('/app/limousine/invoice', navigate: true);
    }

    /** Payment dialog: open when set, with the balance offered. */
    public bool $collecting = false;

    public string $collectAmount = '0';

    public string $collectMethod = 'cash';

    public string $collectNote = '';

    /**
     * Take money against this invoice.
     *
     * Offered as the whole remaining balance, because that is what is being
     * asked for far more often than a part of it — and a figure already in the
     * box is one less thing to get wrong at the counter.
     */
    public function openCollect(): void
    {
        $this->guardAccess(Permission::Write);

        $invoice = $this->invoice();
        if ($invoice === null) {
            return;
        }

        $this->collectAmount = (string) max(0.0, $invoice->balance());
        $this->collectMethod = 'cash';
        $this->collectNote = '';
        $this->collecting = true;
    }

    public function closeCollect(): void
    {
        $this->collecting = false;
    }

    /**
     * Record the payment — through the SAME path the bookings queue uses.
     *
     * One service issues the receipt and settles the booking, so money taken
     * here and money taken at the counter cannot end up meaning different
     * things. An invoice with no trip behind it is not payable this way: the
     * receipt belongs to a job.
     */
    public function saveCollect(BookingPayments $payments): void
    {
        $this->guardAccess(Permission::Write);

        $invoice = $this->invoice();
        if ($invoice === null) {
            return;
        }

        $this->validateFocusing([
            'collectAmount' => ['required', 'numeric', 'min:0.001'],
            'collectMethod' => ['required', 'in:cash,card,benefit,transfer'],
            'collectNote' => ['nullable', 'string', 'max:255'],
        ]);

        $booking = $invoice->booking;
        if ($booking === null) {
            $this->addError('collectAmount', __('Create the trip first — a receipt belongs to a job.'));

            return;
        }

        $payments->receive(
            $booking,
            (float) $this->collectAmount,
            $this->collectMethod,
            $this->collectNote !== '' ? $this->collectNote : null,
        );

        $this->collecting = false;
        session()->flash('toast', __('Payment recorded.'));
    }

    /**
     * Dispatch the journey this invoice bills for.
     *
     * Only reachable on an invoice raised from a quotation — that is what holds
     * the legs. An invoice raised alongside a booking already has its trip.
     */
    public function createTrip(): void
    {
        $this->guardAccess(Permission::Write);

        $invoice = $this->invoice();
        if ($invoice === null) {
            return;
        }

        $booking = $invoice->createTrip();
        if ($booking === null) {
            $this->addError('id', __('This invoice has no quotation to build a trip from.'));

            return;
        }

        session()->flash('toast', __('Trip created from this invoice.'));
        $this->redirect('/app/limousine/booking/' . $booking->id, navigate: true);
    }

    private function invoice(): ?LimoInvoice
    {
        return $this->id !== null
            ? LimoInvoice::query()->with(['booking', 'quotation'])->find($this->id)
            : null;
    }

    public function render(): View
    {
        $invoice = $this->id !== null ? LimoInvoice::query()->with(['receipts', 'booking', 'quotation'])->find($this->id) : null;
        $receipts = $invoice !== null ? $invoice->receipts : collect();
        $balance = $invoice !== null ? $invoice->balance() : $this->previewTotal();

        return view('limousine::invoice-form', [
            'customers' => LimoCustomer::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'phone']),
            'previewTotal' => $this->previewTotal(),
            'balance' => $balance,
            'receipts' => $receipts,
            'invoice' => $invoice,
            // The trip action only makes sense on an invoice that came from a
            // quotation and has not been dispatched yet.
            'canCreateTrip' => $invoice !== null && $invoice->booking_id === null && $invoice->quotation_id !== null,
            'canCollect' => $invoice !== null && $invoice->balance() > 0,
            'isEditing' => $this->id !== null,
        ]);
    }
}
