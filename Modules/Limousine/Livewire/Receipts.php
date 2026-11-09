<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Security\Permission;
use App\Erp\Settings\Setting;
use App\Livewire\Concerns\GuardsModelAccess;
use App\Livewire\Concerns\SelectsListRows;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Limousine\Mail\ReceiptMail;
use Illuminate\Support\Carbon;
use Modules\Limousine\Models\LimoReceipt;
use Modules\Limousine\Services\LimoReceiptPdf;
use Modules\Limousine\Services\LimoReceiptRows;
use Throwable;

#[Layout('components.layouts.app')]
#[Title('Receipts')]
final class Receipts extends Component
{
    use GuardsModelAccess;
    use SelectsListRows;
    use WithPagination;

    /** Rows per page on the Confirmed / All tabs. */
    private const PER_PAGE = 20;

    /** The "To confirm" queue is shown whole, up to this many receipts. */
    private const QUEUE_LIMIT = 500;

    #[Url]
    public string $search = '';

    /** to_confirm · confirmed · all — the first is the accountant's desk. */
    #[Url(except: '')]
    public string $tab = '';

    /** cash · card · benefit · transfer, or empty for every method. */
    #[Url(except: '')]
    public string $method = '';

    /** The receipt's OWN date — when the money changed hands, not today. */
    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    /** A name from {@see LimoReceiptRows::preparers()}, or empty for everyone. */
    #[Url(except: '')]
    public string $preparedBy = '';

    protected function accessModelKey(): string
    {
        return 'limousine.receipt';
    }

    public function mount(): void
    {
        $this->guardAccess(Permission::Read);

        // The accountant lands on their queue; everyone else on the list they
        // came for. Explicitly chosen tabs (in the URL) are left alone.
        if ($this->tab === '') {
            $this->tab = $this->mayConfirm() ? 'to_confirm' : 'all';
        }
    }

    /**
     * Confirming money is the accountant's job — with super-admins, and
     * deliberately NOT regular admins. Taking money and vouching that it
     * arrived should not be the same pair of hands.
     */
    private function mayConfirm(): bool
    {
        return Auth::user()?->canConfirmPayments() ?? false;
    }

    private function guardConfirm(): void
    {
        abort_unless($this->mayConfirm(), 403);
    }

    /** Confirm dialog: a receipt id, or a whole batch. */
    #[Locked]
    public ?int $confirmingId = null;

    #[Locked]
    public ?string $confirmingBatch = null;

    /** The day it showed on the company statement — bank methods only. */
    public string $statementDate = '';

    public function openConfirm(int $id): void
    {
        $this->guardConfirm();

        $receipt = LimoReceipt::query()->find($id);
        if ($receipt === null || $receipt->isConfirmed()) {
            return;
        }

        $this->confirmingId = $receipt->id;
        $this->confirmingBatch = null;
        $this->statementDate = now()->format('Y-m-d');
        $this->resetErrorBag();
    }

    public function openConfirmBatch(string $batchId): void
    {
        $this->guardConfirm();

        $this->confirmingBatch = $batchId;
        $this->confirmingId = null;
        $this->statementDate = now()->format('Y-m-d');
        $this->resetErrorBag();
    }

    public function closeConfirm(): void
    {
        $this->confirmingId = null;
        $this->confirmingBatch = null;
    }

    /**
     * Close the receipt: the money is really here.
     *
     * Cash is vouched for as such; a bank method records the STATEMENT DATE it
     * was found under, because "I saw it on the statement" without saying where
     * is not something anyone can check later.
     */
    public function saveConfirm(): void
    {
        $this->guardConfirm();

        $receipts = $this->confirmingBatch !== null
            ? LimoReceipt::query()->where('batch_id', $this->confirmingBatch)->whereNull('confirmed_at')->get()
            : LimoReceipt::query()->whereKey($this->confirmingId)->whereNull('confirmed_at')->get();

        if ($receipts->isEmpty()) {
            $this->closeConfirm();

            return;
        }

        $cashOnly = $receipts->every(fn (LimoReceipt $r): bool => $r->isCash());
        if (! $cashOnly) {
            $this->validate(['statementDate' => ['required', 'date']]);
        }

        $byName = (string) (Auth::user()->name ?? '');
        $statement = $cashOnly ? null : Carbon::parse($this->statementDate);

        foreach ($receipts as $receipt) {
            $receipt->confirm($byName, $receipt->isCash() ? null : $statement);
        }

        $this->closeConfirm();
        // A ticked receipt that just left the queue would silently drop out of
        // the next download; start the ticks afresh.
        $this->clearSelection();
        session()->flash('toast', trans_choice(
            ':count payment confirmed.|:count payments confirmed.',
            $receipts->count(),
            ['count' => $receipts->count()],
        ));
    }

    /** Taken back — a mistake in confirming, not a deletion of history. */
    public function unconfirm(int $id): void
    {
        $this->guardConfirm();

        LimoReceipt::query()->find($id)?->unconfirm();
    }

    /** Receipt being emailed, or null when the dialog is shut. */
    #[Locked]
    public ?int $sendingId = null;

    /** Where it goes. Offered from the customer, corrected by the office. */
    public string $sendEmail = '';

    /**
     * Offer the address we hold, and let the office correct it.
     *
     * The one on file is often not the one that should get this — a booking is
     * placed by whoever happened to call, and the receipt belongs to whoever
     * paid. Showing it rather than sending silently is the difference between
     * a correction and a receipt that went to the wrong person.
     */
    public function openSend(int $id): void
    {
        $this->guardAccess(Permission::Read);

        $receipt = LimoReceipt::query()->with('customer')->find($id);
        if ($receipt === null) {
            return;
        }

        $this->sendingId = $receipt->id;
        $this->sendEmail = trim((string) ($receipt->customer->email ?? ''));
    }

    public function closeSend(): void
    {
        $this->sendingId = null;
        $this->sendEmail = '';
    }

    /** Email the receipt, with the PDF attached. */
    public function sendReceipt(): void
    {
        $this->guardAccess(Permission::Read);

        if ($this->sendingId === null) {
            return;
        }

        $this->validate(
            ['sendEmail' => ['required', 'email', 'max:255']],
            ['sendEmail.required' => __('An email address is needed to send the receipt.')],
        );

        $receipt = LimoReceipt::query()->with(['customer', 'invoice', 'booking'])->find($this->sendingId);
        if ($receipt === null) {
            $this->closeSend();

            return;
        }

        $pdf = app(LimoReceiptPdf::class);
        $email = trim($this->sendEmail);

        try {
            Mail::to($email)->send(new ReceiptMail(
                receipt: $receipt,
                companyName: (string) Setting::get('company.name', config('app.name')),
                pdf: $pdf->render($receipt),
                filename: $pdf->filename($receipt),
            ));
        } catch (Throwable $e) {
            // Mail leans on something outside the app: a refused message is
            // said in the dialog, as for quotations, not thrown as a 500.
            $this->addError('sendEmail', __('Could not send: :reason', ['reason' => $e->getMessage()]));

            return;
        }

        // Learned for next time: the address the office corrected to is the one
        // this customer should be reached at.
        $customer = $receipt->customer;
        if ($customer !== null && trim((string) ($customer->email ?? '')) === '') {
            $customer->forceFill(['email' => $email])->save();
        }

        $this->closeSend();
        session()->flash('toast', __('Receipt sent to :email', ['email' => $email]));
    }

    /**
     * The ids on what is being looked at, for the header checkbox: the whole
     * accountant queue on "To confirm" (it is not paginated), else the
     * current page — same query and order as the list.
     *
     * @return list<int>
     */
    protected function currentPageIds(): array
    {
        $query = $this->rowsQuery();
        $query = $this->tab === 'to_confirm'
            ? $query->limit(self::QUEUE_LIMIT)
            : $query->forPage($this->getPage(), self::PER_PAGE);

        return $query->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    /**
     * Tick or untick several receipts at once — a bulk payment on the
     * "To confirm" tab is one row holding many receipts, so its box speaks
     * for all of them. All ticked → untick all; otherwise tick all.
     */
    public function toggleIds(string $csv): void
    {
        $ids = array_values(array_unique(array_map('intval', array_filter(explode(',', $csv), 'is_numeric'))));
        if ($ids === []) {
            return;
        }

        $current = array_map('intval', $this->selected);
        $allIn = array_diff($ids, $current) === [];

        $this->selected = $allIn
            ? array_values(array_diff($current, $ids))
            : array_values(array_unique([...$current, ...$ids]));
        $this->selectPage = false;
    }

    /** @return \Illuminate\Database\Eloquent\Builder<LimoReceipt> */
    private function rowsQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return app(LimoReceiptRows::class)->query(
            $this->tab,
            $this->method,
            $this->search,
            $this->from,
            $this->to,
            $this->preparedBy,
        );
    }

    public function updatedTab(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedMethod(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedFrom(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedTo(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedPreparedBy(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->clearSelection();
    }

    public function render(): View
    {
        // The same source the exports read, via LimoReceiptRows — otherwise a
        // filter added here (or there) quietly stops matching what the office
        // sees on screen.
        $query = $this->rowsQuery();

        // The accountant's desk groups a bulk payment into ONE row: nobody
        // should close a lump sum forty receipts at a time. Grouped in PHP over
        // the open items rather than paginated SQL, because a batch split
        // across two pages would show two half-batches with wrong totals — and
        // a queue the accountant clears daily is small.
        $pending = $this->tab === 'to_confirm'
            ? (clone $query)->limit(self::QUEUE_LIMIT)->get()->groupBy(fn (LimoReceipt $r): string => $r->batch_id ?? 'r-' . $r->id)->values()
            : collect();

        return view('limousine::receipts', [
            'receipts' => $this->tab === 'to_confirm' ? null : $query->paginate(self::PER_PAGE),
            'pending' => $pending,
            'pendingCount' => LimoReceipt::query()->whereNull('confirmed_at')->count(),
            'canConfirm' => $this->mayConfirm(),
            'confirmingReceipt' => $this->confirmingId !== null ? LimoReceipt::query()->find($this->confirmingId) : null,
            'confirmingBatchIsCash' => $this->confirmingBatch !== null
                && LimoReceipt::query()->where('batch_id', $this->confirmingBatch)
                    ->get()->every(fn (LimoReceipt $r): bool => $r->isCash()),
            // Money taken on a booking issues its own receipt, so a hand-made
            // one is a correction reserved for whoever may vouch that money
            // arrived — the owner and the Accountant. Same rule server-side in
            // ReceiptForm — hiding the button alone would only be cosmetic.
            'canCreateManually' => $this->mayConfirm(),
            'collectedTotal' => (float) LimoReceipt::query()->sum('amount'),
            'canManage' => ($u = Auth::user()) instanceof User && $u->canApproveMaintenance(),
            'preparedByOptions' => app(LimoReceiptRows::class)->preparers(),
        ]);
    }
}
