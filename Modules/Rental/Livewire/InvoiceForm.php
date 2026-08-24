<?php

declare(strict_types=1);

namespace Modules\Rental\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalInvoice;

/**
 * Bespoke invoice form: edit the bill, see its running balance + receipts, and
 * jump to "Record payment" (which creates a receipt that settles it).
 */
#[Layout('components.layouts.app')]
#[Title('Invoice')]
final class InvoiceForm extends Component
{
    use GuardsModelAccess;

    protected function accessModelKey(): string
    {
        return 'rental.invoice';
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

    // Read-only surface.
    public string $reference = '';

    public string $status = RentalInvoice::STATUS_UNPAID;

    public float $amount_paid = 0;

    public ?int $order_id = null;

    public function mount(?int $id = null): void
    {
        $this->guardAccess(Permission::Read);
        if ($id !== null) {
            $invoice = RentalInvoice::query()->find($id);
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
                $this->order_id = $invoice->order_id;

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
        $this->validate();

        $invoice = $this->id !== null ? RentalInvoice::query()->find($this->id) : new RentalInvoice();
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
        $invoice->recomputePaid(); // keep status correct after a total change

        session()->flash('toast', __('Invoice saved.'));
        $this->redirect('/app/rental/invoice', navigate: true);
    }

    public function render(): View
    {
        $invoice = $this->id !== null ? RentalInvoice::query()->with('receipts')->find($this->id) : null;
        $receipts = $invoice !== null ? $invoice->receipts : collect();
        $balance = $invoice !== null ? $invoice->balance() : $this->previewTotal();

        return view('rental::invoice-form', [
            'customers' => RentalCustomer::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'phone']),
            'previewTotal' => $this->previewTotal(),
            'balance' => $balance,
            'receipts' => $receipts,
            'isEditing' => $this->id !== null,
        ]);
    }
}
