<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Security\Permission;
use App\Livewire\Concerns\GuardsModelAccess;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use App\Livewire\Concerns\SelectsListRows;
use Livewire\WithPagination;
use Modules\Limousine\Services\LimoQuotationRows;
use Modules\Limousine\Mail\QuotationMail;
use Modules\Limousine\Models\LimoQuotation;
use Modules\Limousine\Services\QuotationPdf;
use Throwable;
use App\Erp\Settings\Setting;

#[Layout('components.layouts.app')]
#[Title('Quotations')]
final class Quotations extends Component
{
    use GuardsModelAccess;
    use SelectsListRows;
    use WithPagination;

    #[Url]
    public string $tab = 'all';

    /** Reference or customer name. */
    #[Url(except: '')]
    public string $search = '';

    protected function accessModelKey(): string
    {
        return 'limousine.quotation';
    }

    /** The quotation being emailed, or null when that dialog is shut. */
    #[Locked]
    public ?int $sendingId = null;

    /**
     * Where it goes — offered from the customer's record and then editable.
     *
     * A quote is approved by whoever holds the budget, who is often not the
     * person who asked for it, so the address on file is a starting point
     * rather than the answer.
     */
    public string $sendEmail = '';

    public function mount(): void
    {
        $this->guardAccess(Permission::Read);
    }

    /**
     * Turn a quotation into a booking.
     *
     * The quote is NOT consumed: it stays as the record of what was agreed and
     * for how much, marked converted, with the booking hanging off it. Nothing
     * on this screen removes a quotation — a quote that was sent to a customer
     * is a thing that happened, whether or not it came to anything.
     */
    public function process(int $id): void
    {
        $this->guardAccess(Permission::Write);

        $quote = LimoQuotation::query()->with('legs')->find($id);
        if ($quote === null) {
            return;
        }

        // Accepting a quote raises the INVOICE. The trip is dispatched from
        // that invoice — the customer agrees a price, we bill it, and the
        // journey runs against the bill.
        $invoice = $quote->convertToInvoice();

        session()->flash('quotation_status', __('Quotation :ref is now invoice :invoice.', [
            'ref' => (string) $quote->reference,
            'invoice' => (string) ($invoice->reference ?? $invoice->id),
        ]));

        $this->redirect('/app/limousine/invoice', navigate: true);
    }

    public function openSend(int $id): void
    {
        $this->guardAccess(Permission::Write);

        $quote = LimoQuotation::query()->with('customer')->find($id);
        if ($quote === null) {
            return;
        }

        $this->resetErrorBag();
        $this->sendingId = (int) $quote->id;
        $this->sendEmail = trim((string) ($quote->sent_to ?: $quote->customer?->serviceEmail() ?? ''));
    }

    public function closeSend(): void
    {
        $this->sendingId = null;
        $this->sendEmail = '';
        $this->resetErrorBag();
    }

    /** Email the quotation to the customer, with the PDF attached. */
    public function sendQuotation(): void
    {
        $this->guardAccess(Permission::Write);

        if ($this->sendingId === null) {
            return;
        }

        $quote = LimoQuotation::query()->with(['customer', 'legs'])->find($this->sendingId);
        if ($quote === null) {
            $this->closeSend();

            return;
        }

        $this->validate(
            ['sendEmail' => ['required', 'email', 'max:255']],
            [],
            ['sendEmail' => __('Email')],
        );

        $resent = $quote->sent_at !== null;

        try {
            $pdf = app(QuotationPdf::class);

            Mail::to($this->sendEmail)->send(new QuotationMail(
                quote: $quote,
                companyName: (string) Setting::get('company.name', config('app.name')),
                total: round((float) $quote->fare, 3),
                pdf: $pdf->render($quote),
                filename: $pdf->filename($quote),
            ));
        } catch (Throwable $e) {
            // Mail leans on something outside the app, so a failure is reported
            // where the office is looking rather than arriving as a 500.
            $this->addError('sendEmail', __('Could not send: :reason', ['reason' => $e->getMessage()]));

            return;
        }

        $quote->forceFill([
            'sent_at' => Carbon::now(),
            'sent_to' => trim($this->sendEmail),
            // A quote that has gone out is no longer a draft. An answered one
            // keeps its answer: sending a copy of an accepted quote must not
            // walk it back to "waiting".
            'status' => $quote->status === LimoQuotation::STATUS_DRAFT
                ? LimoQuotation::STATUS_SENT
                : $quote->status,
        ])->save();

        $email = trim($this->sendEmail);
        $this->closeSend();

        session()->flash('quotation_status', $resent
            ? __('Quotation sent again to :email.', ['email' => $email])
            : __('Quotation sent to :email.', ['email' => $email]));
    }

    public function updatedTab(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    // A tick made against one search is not a tick against the next.
    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    /**
     * The ids on the page being looked at, for the header checkbox. Same
     * query and order as the list, so "select all on this page" means what
     * the eye sees.
     *
     * @return list<int>
     */
    protected function currentPageIds(): array
    {
        return app(LimoQuotationRows::class)->query($this->tab, false, $this->search)
            ->forPage($this->getPage(), 20)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    public function render(): View
    {
        // `invoice` because that, not booking_id, is what says a quote has been
        // processed: the trip is dispatched from the invoice, so between those
        // two steps a quote has an invoice and no booking.
        $query = LimoQuotation::query()->with(['customer:id,name', 'invoice:id,quotation_id,reference'])->orderByDesc('id');
        app(LimoQuotationRows::class)->applySearch($query, $this->search);

        if (in_array($this->tab, [
            LimoQuotation::STATUS_DRAFT,
            LimoQuotation::STATUS_SENT,
            LimoQuotation::STATUS_ACCEPTED,
            LimoQuotation::STATUS_DECLINED,
            LimoQuotation::STATUS_CONVERTED,
        ], true)) {
            $query->where('status', $this->tab);
        }

        $counts = LimoQuotation::query()->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');

        $user = Auth::user();

        return view('limousine::quotations', [
            'quotations' => $query->paginate(20),
            'canWrite' => $this->mayAccess(Permission::Write),
            'canManage' => $user instanceof User && $user->canApproveMaintenance(),
            'sending' => $this->sendingId !== null
                ? LimoQuotation::query()->with('customer')->find($this->sendingId)
                : null,
            'counts' => $counts,
            'totalCount' => (int) $counts->sum(),
        ]);
    }
}
