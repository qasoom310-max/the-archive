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
use Modules\Rental\Models\RentalInvoice;
use Modules\Rental\Models\RentalReceipt;

/**
 * Bespoke receipt form: record a payment against an invoice. Reachable
 * standalone ("New receipt") or pre-filled from an invoice's "Record payment"
 * (the `?invoice=` query param). Saving recomputes the invoice's balance.
 */
#[Layout('components.layouts.app')]
#[Title('Receipt')]
final class ReceiptForm extends Component
{
    use GuardsModelAccess;

    protected function accessModelKey(): string
    {
        return 'rental.receipt';
    }

    /** The record being edited — server-set only; the browser must not repoint it. */
    #[Locked]
    public ?int $id = null;

    public ?int $invoice_id = null;

    public string $date = '';

    public string $amount = '0';

    public string $method = 'cash';

    public string $notes = '';

    public string $reference = '';

    public function mount(?int $id = null): void
    {
        $this->guardAccess(Permission::Read);
        if ($id !== null) {
            $receipt = RentalReceipt::query()->find($id);
            if ($receipt !== null) {
                $this->id = $receipt->id;
                $this->invoice_id = $receipt->invoice_id;
                $this->date = $receipt->date?->format('Y-m-d') ?? '';
                $this->amount = (string) $receipt->amount;
                $this->method = $receipt->method;
                $this->notes = $receipt->notes ?? '';
                $this->reference = $receipt->reference ?? '';

                return;
            }
        }

        $this->date = now()->format('Y-m-d');

        // Pre-select an invoice when arriving from its "Record payment" button.
        $preInvoice = request()->integer('invoice');
        if ($preInvoice > 0) {
            $this->invoice_id = $preInvoice;
            $this->syncFromInvoice();
        }
    }

    public function updatedInvoiceId(): void
    {
        $this->syncFromInvoice();
    }

    /** Default the amount to the invoice's outstanding balance. */
    private function syncFromInvoice(): void
    {
        $invoice = $this->invoice_id !== null ? RentalInvoice::query()->find($this->invoice_id) : null;
        if ($invoice !== null) {
            $this->amount = (string) max(0.0, $invoice->balance());
        }
    }

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'invoice_id' => ['required', 'integer'],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.001'],
            'method' => ['required', 'in:cash,card,benefit,transfer'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function save(): void
    {
        $this->guardSave($this->id === null);
        $this->validate();

        $invoice = RentalInvoice::query()->find($this->invoice_id);
        if ($invoice === null) {
            return;
        }

        $receipt = $this->id !== null ? RentalReceipt::query()->find($this->id) : new RentalReceipt();
        if ($receipt === null) {
            return;
        }

        $receipt->invoice_id = $invoice->id;
        $receipt->customer_id = $invoice->customer_id;
        $receipt->date = Carbon::parse($this->date);
        $receipt->amount = (float) $this->amount;
        $receipt->method = $this->method;
        $receipt->notes = $this->notes !== '' ? $this->notes : null;
        $receipt->save();

        // created-hook recomputes on insert; do it explicitly so edits also
        // re-settle the invoice.
        $invoice->refresh()->recomputePaid();

        session()->flash('toast', __('Payment recorded.'));
        $this->redirect('/app/rental/invoice/' . $invoice->id, navigate: true);
    }

    public function render(): View
    {
        $invoice = $this->invoice_id !== null ? RentalInvoice::query()->with('customer:id,name')->find($this->invoice_id) : null;

        return view('rental::receipt-form', [
            // Pickable invoices: anything not fully paid, plus the current one.
            'invoices' => RentalInvoice::query()
                ->with('customer:id,name')
                ->where(function ($q): void {
                    $q->where('status', '!=', RentalInvoice::STATUS_PAID);
                    if ($this->invoice_id !== null) {
                        $q->orWhere('id', $this->invoice_id);
                    }
                })
                ->orderByDesc('id')
                ->get(),
            'selectedInvoice' => $invoice,
            'isEditing' => $this->id !== null,
        ]);
    }
}
