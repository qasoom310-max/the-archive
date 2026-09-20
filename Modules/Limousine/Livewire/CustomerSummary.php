<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoQuotation;
use Modules\Limousine\Models\LimoReceipt;
use Modules\Limousine\Services\AccountPayment;

/**
 * Everything one customer has asked us for, on one page.
 *
 * A company like Dadabhai books over and over, and until now the only way to
 * see their account was to search the queue and read rows off it — which shows
 * trips but never answers "what do they owe us" or "what have they asked for
 * that we have not priced yet".
 *
 * The money comes from the INVOICES, not from the bookings' own fare/advance:
 * the invoice is the document the customer owes under, and it stops following
 * a trip's price once money has landed against it. Adding up fares instead
 * would quietly re-price history.
 */
#[Layout('components.layouts.app')]
#[Title('Customer')]
final class CustomerSummary extends Component
{
    use GuardsModelAccess;

    /** Whose account this is — server-set; the browser must not repoint it. */
    #[Locked]
    public int $id = 0;

    protected function accessModelKey(): string
    {
        return 'limousine.customer';
    }

    public function mount(int $id): void
    {
        $this->guardAccess(Permission::Read);
        $this->id = $id;
    }

    /**
     * The statement period. In the URL so a range can be sent to a colleague,
     * and so the download link and the screen can never disagree about it.
     */
    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    /** Late-fee dialog: open when set. */
    public bool $charging = false;

    public string $feeAmount = '0';

    public string $feeDate = '';

    public string $feePeriod = '';

    public string $feeReason = '';

    /**
     * Raising a penalty is a management decision, not a counter action.
     *
     * Taking money that is owed is ordinary work; deciding the customer owes
     * MORE than we quoted is not, so it takes an admin. mount() would not hold
     * it on its own — Livewire dispatches straight to methods.
     */
    private function guardCharge(): void
    {
        abort_unless(Auth::user()?->isAdmin() ?? false, 403);
    }

    public function openFee(): void
    {
        $this->guardCharge();

        $this->feeAmount = '0';
        $this->feeDate = now()->format('Y-m-d');
        // Prefilled from the statement period when one is set: a late fee is
        // almost always charged for the months just looked at.
        $this->feePeriod = $this->periodLabel();
        $this->feeReason = '';
        $this->resetErrorBag();
        $this->charging = true;
    }

    public function closeFee(): void
    {
        $this->charging = false;
    }

    /** "June 2026 — July 2026" from the statement range, or this month. */
    private function periodLabel(): string
    {
        if ($this->from === '' && $this->to === '') {
            return now()->isoFormat('MMMM YYYY');
        }

        $start = $this->from !== '' ? Carbon::parse($this->from)->isoFormat('MMMM YYYY') : '';
        $end = $this->to !== '' ? Carbon::parse($this->to)->isoFormat('MMMM YYYY') : '';

        if ($start === '' || $start === $end) {
            return $end !== '' ? $end : $start;
        }

        return $end === '' ? $start : $start . ' — ' . $end;
    }

    /**
     * Charge a late-payment penalty.
     *
     * Raised as an invoice, because that is where every other debt lives — a
     * fee in its own side table would have to be taught to the statement, the
     * outstanding balance and the payment screens separately, and one of them
     * would be forgotten. The label is what the document prints instead of a
     * route.
     */
    public function saveFee(): void
    {
        $this->guardCharge();

        $customer = LimoCustomer::query()->find($this->id);
        if ($customer === null) {
            return;
        }

        $this->validate([
            'feeAmount' => ['required', 'numeric', 'min:0.001'],
            'feeDate' => ['required', 'date'],
            'feePeriod' => ['required', 'string', 'max:120'],
            'feeReason' => ['nullable', 'string', 'max:255'],
        ]);

        $amount = round((float) $this->feeAmount, 3);
        $invoice = new LimoInvoice();
        $invoice->customer_id = $customer->id;
        $invoice->issue_date = Carbon::parse($this->feeDate);
        $invoice->due_date = Carbon::parse($this->feeDate);
        $invoice->subtotal = $amount;
        $invoice->total = $amount;
        $invoice->charge_label = __('Late payment charge — :period', ['period' => $this->feePeriod]);
        $invoice->notes = $this->feeReason !== '' ? $this->feeReason : null;
        $invoice->save();

        $this->charging = false;
        session()->flash('toast', __('Late payment charge added.'));
    }

    /** Pay-on-account dialog: open when set. */
    public bool $paying = false;

    public string $payAmount = '0';

    public string $payMethod = 'cash';

    public string $payNote = '';

    /**
     * Take a lump sum against the whole account.
     *
     * A company hands over a round figure rather than settling one trip, so the
     * whole outstanding balance is offered — the office types over it when the
     * customer is paying less, which is the usual case.
     */
    public function openPay(AccountPayment $account): void
    {
        $this->guardAccess(Permission::Write);

        $customer = LimoCustomer::query()->find($this->id);
        if ($customer === null) {
            return;
        }

        $owed = round((float) $account->settleable($customer)->sum(
            fn (LimoInvoice $invoice): float => $invoice->balance()
        ), 3);

        $this->payAmount = (string) $owed;
        $this->payMethod = 'cash';
        $this->payNote = '';
        $this->resetErrorBag();
        $this->paying = true;
    }

    public function closePay(): void
    {
        $this->paying = false;
    }

    /**
     * Spread the payment across the account, oldest bill first.
     *
     * Refused when it is more than the account can absorb: money with nowhere
     * to go would either vanish or sit as an untracked credit, and neither is
     * something the office could later explain.
     */
    public function savePay(AccountPayment $account): void
    {
        $this->guardAccess(Permission::Write);

        $customer = LimoCustomer::query()->find($this->id);
        if ($customer === null) {
            return;
        }

        $this->validate([
            'payAmount' => ['required', 'numeric', 'min:0.001'],
            'payMethod' => ['required', 'in:' . implode(',', array_column(LimoReceipt::methodOptions(), 'value'))],
            'payNote' => ['nullable', 'string', 'max:255'],
        ]);

        $settleable = round((float) $account->settleable($customer)->sum(
            fn (LimoInvoice $invoice): float => $invoice->balance()
        ), 3);

        if ($settleable <= 0.0) {
            $this->addError('payAmount', __('Nothing on this account can take a payment yet.'));

            return;
        }

        if (round((float) $this->payAmount, 3) > $settleable + 0.0005) {
            $this->addError('payAmount', __('That is more than this account owes (:amount).', [
                'amount' => \App\Erp\Views\ValueFormat::money($settleable),
            ]));

            return;
        }

        $result = $account->settle($customer, (float) $this->payAmount, $this->payMethod, $this->payNote !== '' ? $this->payNote : null);

        $this->paying = false;
        session()->flash('toast', __(':amount received across :count invoices.', [
            'amount' => \App\Erp\Views\ValueFormat::money($result['allocated']),
            'count' => $result['receipts'],
        ]));
    }

    /** How many of each are shown before the "see all" link takes over. */
    private const SHOWN = 15;

    public function render(AccountPayment $account): View
    {
        $customer = LimoCustomer::query()->findOrFail($this->id);

        $invoices = LimoInvoice::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('id')
            ->get();

        $billed = round((float) $invoices->sum('total'), 3);
        $received = round((float) $invoices->sum('amount_paid'), 3);

        // Their trips, newest first — the same unit the queue dispatches, so
        // what they see here matches what they see there.
        $legs = LimoLeg::query()
            ->whereMorphedTo('legable', LimoBooking::class)
            ->whereHasMorph('legable', LimoBooking::class, fn ($b) => $b->where('customer_id', $customer->id))
            ->with('legable')
            ->orderByDesc('start_at')
            ->orderBy('sequence')
            ->limit(self::SHOWN)
            ->get();

        $tripCount = LimoLeg::query()
            ->whereMorphedTo('legable', LimoBooking::class)
            ->whereHasMorph('legable', LimoBooking::class, fn ($b) => $b->where('customer_id', $customer->id))
            ->count();

        // Quotes still waiting on an answer: asked for, not yet billed. The
        // half of an account that a list of trips cannot show.
        $openQuotes = LimoQuotation::query()
            ->where('customer_id', $customer->id)
            ->doesntHave('invoice')
            ->whereNull('booking_id')
            ->where('status', '!=', LimoQuotation::STATUS_DECLINED)
            ->orderByDesc('id')
            ->limit(self::SHOWN)
            ->get();

        return view('limousine::customer-summary', [
            'customer' => $customer,
            'billed' => $billed,
            'received' => $received,
            // Floored: an overpaid account is settled, not owed a negative.
            'outstanding' => round(max(0.0, $billed - $received), 3),
            'tripCount' => $tripCount,
            'legs' => $legs,
            'openInvoices' => $invoices->filter(fn (LimoInvoice $i) => $i->balance() > 0)->take(self::SHOWN),
            'invoiceCount' => $invoices->count(),
            'openQuotes' => $openQuotes,
            'shown' => self::SHOWN,
            // What is actually payable, and how the typed figure would land.
            // A payment that spreads itself silently is one nobody can check.
            'settleable' => $account->settleable($customer),
            'canCharge' => Auth::user()?->isAdmin() ?? false,
            'statementUrl' => url('/app/limousine/customer/' . $customer->id . '/statement'
                . '?from=' . urlencode($this->from) . '&to=' . urlencode($this->to)),
            'payPlan' => $this->paying ? $account->plan($customer, (float) $this->payAmount) : [],
        ]);
    }
}
