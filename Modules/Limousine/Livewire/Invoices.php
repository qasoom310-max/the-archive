<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;
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
        $query = LimoInvoice::query()->with('customer:id,name')->orderByDesc('id');

        if (in_array($this->tab, [LimoInvoice::STATUS_UNPAID, LimoInvoice::STATUS_PARTIAL, LimoInvoice::STATUS_PAID], true)) {
            $query->where('status', $this->tab);
        }

        $counts = LimoInvoice::query()->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');

        $collecting = $this->collectingId !== null ? LimoInvoice::query()->find($this->collectingId) : null;

        return view('limousine::invoices', [
            'invoices' => $query->paginate(20),
            'collecting' => $collecting,
            'collectBalance' => $collecting !== null ? $collecting->balance() : 0.0,
            'counts' => $counts,
            'totalCount' => (int) $counts->sum(),
        ]);
    }
}
