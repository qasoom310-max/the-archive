<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoReceipt;

/**
 * Bespoke limousine receipt form: record a payment against an invoice (pre-fill
 * from `?invoice=`). Saving recomputes the invoice balance.
 */
#[Layout('components.layouts.app')]
#[Title('Receipt')]
final class ReceiptForm extends Component
{
    public ?int $id = null;

    public ?int $invoice_id = null;

    public string $date = '';

    public string $amount = '0';

    public string $method = 'cash';

    public string $notes = '';

    public string $reference = '';

    public function mount(?int $id = null): void
    {
        if ($id !== null) {
            $receipt = LimoReceipt::query()->find($id);
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

    private function syncFromInvoice(): void
    {
        $invoice = $this->invoice_id !== null ? LimoInvoice::query()->find($this->invoice_id) : null;
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
        $this->validate();

        $invoice = LimoInvoice::query()->find($this->invoice_id);
        if ($invoice === null) {
            return;
        }

        $receipt = $this->id !== null ? LimoReceipt::query()->find($this->id) : new LimoReceipt();
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

        $invoice->refresh()->recomputePaid();

        session()->flash('toast', __('Payment recorded.'));
        $this->redirect('/app/limousine/invoice/' . $invoice->id, navigate: true);
    }

    public function render(): View
    {
        $invoice = $this->invoice_id !== null ? LimoInvoice::query()->with('customer:id,name')->find($this->invoice_id) : null;

        return view('limousine::receipt-form', [
            'invoices' => LimoInvoice::query()
                ->with('customer:id,name')
                ->where(function ($q): void {
                    $q->where('status', '!=', LimoInvoice::STATUS_PAID);
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
