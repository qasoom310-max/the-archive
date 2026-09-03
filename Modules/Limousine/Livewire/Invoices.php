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
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoInvoice;
use Modules\Limousine\Services\BookingPayments;

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
        $query = LimoInvoice::query()->with('customer:id,name')->orderByDesc('id');

        if (in_array($this->tab, [LimoInvoice::STATUS_UNPAID, LimoInvoice::STATUS_PARTIAL, LimoInvoice::STATUS_PAID], true)) {
            $query->where('status', $this->tab);
        }

        if ($this->from !== '') {
            $query->whereDate('issue_date', '>=', $this->from);
        }
        if ($this->to !== '') {
            $query->whereDate('issue_date', '<=', $this->to);
        }

        $term = trim($this->search);
        if ($term !== '') {
            // Everything the office would reach for: the bill's number, the
            // customer, the trip it bills for, and what a charge is called.
            // Grouped so the ORs cannot widen the tab and date filters above.
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';

            $query->where(function ($q) use ($like): void {
                $q->where('reference', 'like', $like)
                    ->orWhere('charge_label', 'like', $like)
                    ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like))
                    ->orWhereHas('booking', fn ($b) => $b->where('reference', 'like', $like));
            });
        }

        return $query;
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
            'collectMethod' => ['required', 'in:cash,card,benefit,transfer'],
            'collectNote' => ['nullable', 'string', 'max:255'],
        ]);

        if ($invoice->isAwaitingTrip()) {
            $this->addError('collectAmount', __('Create the trip first — a receipt belongs to a job.'));

            return;
        }

        $note = $this->collectNote !== '' ? $this->collectNote : null;
        $booking = $invoice->booking;

        if ($booking !== null) {
            $payments->receive($booking, (float) $this->collectAmount, $this->collectMethod, $note);
        } else {
            // A charge with no journey behind it — a late fee. The receipt is
            // written against the document alone.
            $payments->receiveForCharge($invoice, (float) $this->collectAmount, $this->collectMethod, $note);
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
            ? LimoInvoice::query()->whereIn('id', $this->selected)->get(['id', 'customer_id'])
            : collect();
        $customerIds = $picked->pluck('customer_id')->unique()->values();
        $user = Auth::user();

        return view('limousine::invoices', [
            'canManage' => $user instanceof User && $user->canApproveMaintenance(),
            'invoices' => $query->paginate(20),
            'collecting' => $collecting,
            'selectedCount' => $picked->count(),
            'mixedCustomers' => $customerIds->count() > 1,
            'combinedUrl' => $picked->count() > 0 && $customerIds->count() === 1
                ? url('/app/limousine/invoice/combined?ids=' . implode(',', $this->selected))
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
