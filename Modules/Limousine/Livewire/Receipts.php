<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Security\Permission;
use App\Erp\Settings\Setting;
use App\Livewire\Concerns\GuardsModelAccess;
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

#[Layout('components.layouts.app')]
#[Title('Receipts')]
final class Receipts extends Component
{
    use GuardsModelAccess;
    use WithPagination;

    #[Url]
    public string $search = '';

    /** to_confirm · confirmed · all — the first is the accountant's desk. */
    #[Url(except: '')]
    public string $tab = '';

    /** cash · card · benefit · transfer, or empty for every method. */
    #[Url(except: '')]
    public string $method = '';

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

        Mail::to($email)->send(new ReceiptMail(
            receipt: $receipt,
            companyName: (string) Setting::get('company.name', config('app.name')),
            pdf: $pdf->render($receipt),
            filename: $pdf->filename($receipt),
        ));

        // Learned for next time: the address the office corrected to is the one
        // this customer should be reached at.
        $customer = $receipt->customer;
        if ($customer !== null && trim((string) ($customer->email ?? '')) === '') {
            $customer->forceFill(['email' => $email])->save();
        }

        $this->closeSend();
        session()->flash('toast', __('Receipt sent to :email', ['email' => $email]));
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function updatedMethod(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $query = LimoReceipt::query()
            ->with(['customer:id,name', 'invoice:id,reference'])
            ->orderByDesc('id');

        if ($this->tab === 'to_confirm') {
            $query->whereNull('confirmed_at');
        } elseif ($this->tab === 'confirmed') {
            $query->whereNotNull('confirmed_at');
        }

        if (in_array($this->method, ['cash', 'card', 'benefit', 'transfer'], true)) {
            $query->where('method', $this->method);
        }

        $term = trim($this->search);
        if ($term !== '') {
            $query->where(function ($q) use ($term): void {
                $q->where('reference', 'like', "%{$term}%")
                    ->orWhereHas('customer', function ($c) use ($term): void {
                        $c->where('name', 'like', "%{$term}%");
                    });
            });
        }

        // The accountant's desk groups a bulk payment into ONE row: nobody
        // should close a lump sum forty receipts at a time. Grouped in PHP over
        // the open items rather than paginated SQL, because a batch split
        // across two pages would show two half-batches with wrong totals — and
        // a queue the accountant clears daily is small.
        $pending = $this->tab === 'to_confirm'
            ? (clone $query)->limit(500)->get()->groupBy(fn (LimoReceipt $r): string => $r->batch_id ?? 'r-' . $r->id)->values()
            : collect();

        return view('limousine::receipts', [
            'receipts' => $this->tab === 'to_confirm' ? null : $query->paginate(20),
            'pending' => $pending,
            'pendingCount' => LimoReceipt::query()->whereNull('confirmed_at')->count(),
            'canConfirm' => $this->mayConfirm(),
            'confirmingReceipt' => $this->confirmingId !== null ? LimoReceipt::query()->find($this->confirmingId) : null,
            'confirmingBatchIsCash' => $this->confirmingBatch !== null
                && LimoReceipt::query()->where('batch_id', $this->confirmingBatch)
                    ->get()->every(fn (LimoReceipt $r): bool => $r->isCash()),
            // Money taken on a booking issues its own receipt, so a hand-made
            // one is a correction reserved for the owner. Same rule server-side
            // in ReceiptForm — hiding the button alone would only be cosmetic.
            'canCreateManually' => Auth::user()?->isSuperAdmin() ?? false,
            'collectedTotal' => (float) LimoReceipt::query()->sum('amount'),
            'canManage' => ($u = Auth::user()) instanceof User && $u->canApproveMaintenance(),
        ]);
    }
}
