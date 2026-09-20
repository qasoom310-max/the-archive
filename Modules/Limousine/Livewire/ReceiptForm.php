<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use App\Livewire\Concerns\ScrollsToFirstError;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
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
    use GuardsModelAccess;
    use ScrollsToFirstError;

    protected function accessModelKey(): string
    {
        return 'limousine.receipt';
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

    /**
     * This screen is reserved to whoever may vouch that money arrived: the
     * owner, and the Accountant.
     *
     * Money taken on a booking issues its own receipt, so touching one by hand
     * — writing it or repairing it — is a correction rather than the normal way
     * in, and two receipts for the same payment is a hard mistake to spot after
     * the fact. Same pairing as {@see \App\Models\User::canConfirmPayments()} —
     * confirming a receipt and writing one by hand are both "vouching for
     * money", so they stay behind the same gate.
     */
    private function guardManualCreate(): void
    {
        abort_unless(Auth::user()?->canConfirmPayments() ?? false, 403);
    }

    public function mount(int|string|null $id = null): void
    {
        // A route segment is always a string, and a non-numeric one
        // ("new") means a new record rather than a bad request.
        $id = is_numeric($id) ? (int) $id : null;

        $this->guardAccess(Permission::Read);

        // Nothing links here any more: a receipt is a record of money already
        // taken, and the list offers Download and Send instead. The screen is
        // kept only so the owner can write one by hand, or repair one — so the
        // same rule guards BOTH doors rather than just the new one.
        $this->guardManualCreate();
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
        $this->guardSave($this->id === null);
        if ($this->id === null) {
            // mount() gates are not gates on their own — Livewire dispatches to
            // methods directly, so the create path re-checks here.
            $this->guardManualCreate();
        }
        $this->validateFocusing();

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
        $this->redirect('/app/limousine/invoice', navigate: true);
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
