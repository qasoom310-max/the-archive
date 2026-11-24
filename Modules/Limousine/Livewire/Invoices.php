<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Limousine\Models\LimoBulkInvoice;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Models\LimoReceipt;
use Modules\Limousine\Services\BookingPayments;
use Modules\Limousine\Services\BulkInvoices;
use Modules\Limousine\Services\CustomerDocumentEmails;
use Modules\Limousine\Support\TripInvoiceLookup;

/**
 * The bills, and the three things anyone does with one.
 *
 * There is no invoice page to open: a bill is a document, not a workspace, so
 * the row offers Download, Create trip and Receive payment and nothing else —
 * the same shape the receipts list took for the same reason. Everything here
 * goes through the services the rest of the module uses, so money taken from a
 * row and money taken at the counter cannot come to mean different things.
 */
#[Layout('components.layouts.app')]
#[Title('Invoices')]
final class Invoices extends Component
{
    use GuardsModelAccess;
    use WithPagination;

    #[Url]
    public string $tab = 'all';

    /** Free text across the bill, the customer and the trip it bills for. */
    #[Url(except: '')]
    public string $search = '';

    /** Issue-date window — how "this month's invoices" is asked for. */
    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    /**
     * Invoices ticked for a combined bill.
     *
     * Kept on the component rather than in the URL so a selection survives
     * paging and re-filtering: picking a month, ticking it, then picking
     * another month is exactly how a quarter gets billed in one go.
     *
     * @var list<int>
     */
    public array $selected = [];

    /** Show only the invoices of this bulk invoice (opened from its row). */
    #[Url(as: 'bulk', except: 0)]
    public int $bulkFilter = 0;

    /** What the last bulk-invoice action did, said on the page. */
    public string $notice = '';

    /** Payment dialog for a whole bulk invoice, or null. */
    #[Locked]
    public ?int $payingBulkId = null;

    protected function accessModelKey(): string
    {
        return 'limousine.invoice';
    }

    public function mount(): void
    {
        $this->guardAccess(Permission::Read);
    }

    public function updatedTab(): void
    {
        $this->bulkFilter = 0;
        $this->notice = '';
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFrom(): void
    {
        $this->resetPage();
    }

    public function updatedTo(): void
    {
        $this->resetPage();
    }

    /** Tick everything the current filter shows, not just this page. */
    public function selectAll(): void
    {
        $this->selected = $this->baseQuery()->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    /**
     * The list as filtered, before paging.
     *
     * @return \Illuminate\Database\Eloquent\Builder<LimoInvoice>
     */
    private function baseQuery(): \Illuminate\Database\Eloquent\Builder
    {
        // One definition of the list, shared with its exports.
        return app(\Modules\Limousine\Services\LimoInvoiceRows::class)
            ->query($this->tab, $this->from, $this->to, $this->search)
            ->when($this->bulkFilter > 0, fn ($q) => $q->where('bulk_invoice_id', $this->bulkFilter));
    }

    /**
     * Save the ticked invoices as one bulk invoice.
     *
     * The service refuses a mix of customers and any invoice that is already
     * in a bulk invoice; its message lands under the `bulk` error.
     */
    public function createBulk(BulkInvoices $bulks): void
    {
        $this->guardAccess(Permission::Write);
        $this->resetErrorBag('bulk');

        $bulk = $bulks->create($this->selected, $this->from, $this->to, (int) Auth::id());
        app(\App\Erp\Activity\ActivityLogger::class)->log('bulk_invoice_created', (string) $bulk->reference);

        $this->selected = [];
        $this->tab = 'bulk';
        $this->bulkFilter = 0;
        $this->resetPage();
        $this->notice = __(':reference is saved. Download it here and send it to the customer.', ['reference' => (string) $bulk->reference]);
    }

    /** Drop from the selection whatever is already in a bulk invoice. */
    public function untickBulked(): void
    {
        $free = LimoInvoice::query()->whereIn('id', $this->selected)->whereNull('bulk_invoice_id')->pluck('id');

        $this->selected = $free->map(fn ($id): int => (int) $id)->all();
        $this->resetErrorBag('bulk');
    }

    /** Open the invoices of one bulk invoice in the main list. */
    public function showBulk(int $id): void
    {
        $this->tab = 'all';
        $this->search = '';
        $this->from = '';
        $this->to = '';
        $this->bulkFilter = $id;
        $this->notice = '';
        $this->resetPage();
    }

    public function clearBulkFilter(): void
    {
        $this->bulkFilter = 0;
        $this->resetPage();
    }

    public function openBulkPay(int $id, BulkInvoices $bulks): void
    {
        $this->guardAccess(Permission::Write);

        $bulk = LimoBulkInvoice::query()->find($id);
        if ($bulk === null || $bulk->isCancelled()) {
            return;
        }

        $this->payingBulkId = $bulk->id;
        $this->collectAmount = (string) $bulks->payableBalance($bulk);
        $this->collectMethod = 'cash';
        $this->collectNote = '';
        $this->resetErrorBag();
    }

    public function closeBulkPay(): void
    {
        $this->payingBulkId = null;
    }

    /** One payment for the whole bulk invoice, shared over its invoices. */
    public function saveBulkPay(BulkInvoices $bulks): void
    {
        $this->guardAccess(Permission::Write);

        $bulk = $this->payingBulkId !== null ? LimoBulkInvoice::query()->find($this->payingBulkId) : null;
        if ($bulk === null) {
            return;
        }

        $this->validate([
            'collectAmount' => ['required', 'numeric', 'min:0.001'],
            'collectMethod' => ['required', 'in:' . implode(',', array_column(LimoReceipt::methodOptions(), 'value'))],
            'collectNote' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $bulks->pay($bulk, (float) $this->collectAmount, $this->collectMethod, $this->collectNote !== '' ? $this->collectNote : null);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->addError('collectAmount', (string) ($e->validator->errors()->first('bulk') ?: $e->getMessage()));

            return;
        }

        app(\App\Erp\Activity\ActivityLogger::class)->log('bulk_invoice_paid', (string) $bulk->reference);

        $this->payingBulkId = null;
        $this->notice = __('Payment recorded on :reference: :count receipts.', [
            'reference' => (string) $bulk->reference,
            'count' => $result['receipts'],
        ]);
    }

    /** Mark it as sent to the customer today, or take the mark off. */
    public function toggleBulkSent(int $id): void
    {
        $this->guardAccess(Permission::Write);

        $bulk = LimoBulkInvoice::query()->find($id);
        if ($bulk === null || $bulk->isCancelled()) {
            return;
        }

        $bulk->sent_at = $bulk->sent_at === null ? now() : null;
        $bulk->save();
    }

    /** Cancel a bulk invoice and free its invoices (managers only). */
    public function cancelBulk(int $id, BulkInvoices $bulks): void
    {
        $this->guardAccess(Permission::Write);
        $user = Auth::user();
        abort_unless($user instanceof User && $user->canApproveMaintenance(), 403);

        $bulk = LimoBulkInvoice::query()->find($id);
        if ($bulk === null || $bulk->isCancelled()) {
            return;
        }

        $bulks->cancel($bulk, (int) $user->getKey());
        app(\App\Erp\Activity\ActivityLogger::class)->log('bulk_invoice_cancelled', (string) $bulk->reference);

        $this->notice = __(':reference is cancelled. Its invoices are free again.', ['reference' => (string) $bulk->reference]);
    }

    /** Payment dialog: the invoice being collected against, or null. */
    #[Locked]
    public ?int $collectingId = null;

    public string $collectAmount = '0';

    public string $collectMethod = 'cash';

    public string $collectNote = '';

    /**
     * Take money against a bill, with the whole balance offered.
     *
     * That is what is being asked for far more often than a part of it, and a
     * figure already in the box is one less thing to get wrong at the counter.
     */
    public function openCollect(int $id): void
    {
        $this->guardAccess(Permission::Write);

        $invoice = LimoInvoice::query()->find($id);
        if ($invoice === null) {
            return;
        }

        $this->collectingId = $invoice->id;
        $this->collectAmount = (string) max(0.0, $invoice->balance());
        $this->collectMethod = 'cash';
        $this->collectNote = '';
        $this->resetErrorBag();
    }

    public function closeCollect(): void
    {
        $this->collectingId = null;
    }

    /**
     * Record the payment through the SAME service the bookings queue uses, so
     * one receipt and one truth come out of either door. An invoice with no
     * trip behind it is not payable: a receipt belongs to a job.
     */
    public function saveCollect(BookingPayments $payments): void
    {
        $this->guardAccess(Permission::Write);

        $invoice = $this->collectingId !== null
            ? LimoInvoice::query()->with('booking')->find($this->collectingId)
            : null;

        if ($invoice === null) {
            return;
        }

        $this->validate([
            'collectAmount' => ['required', 'numeric', 'min:0.001'],
            'collectMethod' => ['required', 'in:' . implode(',', array_column(LimoReceipt::methodOptions(), 'value'))],
            'collectNote' => ['nullable', 'string', 'max:255'],
        ]);

        if ($invoice->isAwaitingTrip()) {
            $this->addError('collectAmount', __('Create the trip first — a receipt belongs to a job.'));

            return;
        }

        $note = $this->collectNote !== '' ? $this->collectNote : null;
        $booking = $invoice->booking;

        // A charge with no journey behind it (a late fee) is receipted against
        // the document alone.
        $receipt = $booking !== null
            ? $payments->receive($booking, (float) $this->collectAmount, $this->collectMethod, $note)
            : $payments->receiveForCharge($invoice, (float) $this->collectAmount, $this->collectMethod, $note);
        if ($receipt !== null) {
            app(CustomerDocumentEmails::class)->paymentReceived((int) $receipt->id);
        }

        $this->collectingId = null;
        session()->flash('toast', __('Payment recorded.'));
    }

    /**
     * Dispatch the journey a bill is for.
     *
     * Only reachable on an invoice raised from a quotation — that is what holds
     * the legs. An invoice issued alongside a booking already has its trip.
     */
    public function createTrip(int $id): void
    {
        $this->guardAccess(Permission::Write);

        $invoice = LimoInvoice::query()->find($id);
        if ($invoice === null) {
            return;
        }

        $booking = $invoice->createTrip();
        if ($booking === null) {
            session()->flash('toast', __('This invoice has no quotation to build a trip from.'));

            return;
        }

        $this->redirect('/app/limousine/booking/' . $booking->id, navigate: true);
    }

    public function render(): View
    {
        $query = $this->baseQuery();

        $counts = LimoInvoice::query()->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');

        $collecting = $this->collectingId !== null ? LimoInvoice::query()->find($this->collectingId) : null;

        // A combined bill is ONE customer's — a document addressed to two
        // companies is not a document. Said plainly rather than silently
        // dropping the odd ones out.
        $picked = $this->selected !== []
            ? LimoInvoice::query()->whereIn('id', $this->selected)->get(['id', 'customer_id', 'bulk_invoice_id'])
            : collect();
        $pickedBulked = $picked->filter(static fn (LimoInvoice $i): bool => $i->bulk_invoice_id !== null)->count();

        // The Bulk invoices tab lists the saved bulk invoices instead.
        $onBulkTab = $this->tab === 'bulk';
        $term = trim($this->search);
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $term) . '%';
        $bulks = $onBulkTab
            ? LimoBulkInvoice::query()
                ->with(['customer:id,name', 'invoices:id,bulk_invoice_id,reference,total,amount_paid'])
                ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                    ->where('reference', 'like', $like)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like))
                    ->orWhereHas('invoices', fn ($i) => $i->where('reference', 'like', $like))))
                ->orderByDesc('id')
                ->paginate(20)
            : null;
        $payingBulk = $this->payingBulkId !== null ? LimoBulkInvoice::query()->with('invoices')->find($this->payingBulkId) : null;
        $shownBulk = $this->bulkFilter > 0 ? LimoBulkInvoice::query()->find($this->bulkFilter) : null;
        $customerIds = $picked->pluck('customer_id')->unique()->values();
        $user = Auth::user();

        return view('limousine::invoices', [
            'canManage' => $user instanceof User && $user->canApproveMaintenance(),
            // Searched a trip number: say which invoice bills it, and why it
            // may not be in the list (none yet, already in a bulk invoice,
            // outside the dates or tab picked). Worked out before paging.
            'tripHints' => ! $onBulkTab && TripInvoiceLookup::isTripNumber($term)
                ? TripInvoiceLookup::hints($term, (clone $query)->pluck('id')->map(fn ($id): int => (int) $id)->all())
                : [],
            'invoices' => $onBulkTab ? null : $query->with([
                'bulkInvoice:id,reference',
                // The trip numbers on each row: what the PDF prints as "Ref. #".
                'booking.legs' => fn ($l) => $l->select(['id', 'legable_type', 'legable_id', 'reference', 'status', 'sequence'])->orderBy('sequence'),
            ])->paginate(20),
            'onBulkTab' => $onBulkTab,
            'bulks' => $bulks,
            'bulkCount' => LimoBulkInvoice::query()->whereNull('cancelled_at')->count(),
            'payingBulk' => $payingBulk,
            'payingBulkBalance' => $payingBulk !== null ? app(BulkInvoices::class)->payableBalance($payingBulk) : 0.0,
            'shownBulk' => $shownBulk,
            'pickedBulked' => $pickedBulked,
            'collecting' => $collecting,
            'selectedCount' => $picked->count(),
            'mixedCustomers' => $customerIds->count() > 1,
            'combinedUrl' => $picked->count() > 0 && $customerIds->count() === 1
                ? url('/app/limousine/invoice/combined?' . http_build_query(array_filter([
                    'ids' => implode(',', $this->selected),
                    // The window picked, so the bill's Period says which month it is.
                    'from' => $this->from, 'to' => $this->to,
                ], static fn (string $v): bool => $v !== '')))
                : null,
            'pickedCustomer' => $customerIds->count() === 1
                ? LimoCustomer::query()->find($customerIds->first())?->name
                : null,
            'collectBalance' => $collecting !== null ? $collecting->balance() : 0.0,
            'counts' => $counts,
            'totalCount' => (int) $counts->sum(),
        ]);
    }
}
