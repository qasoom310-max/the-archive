<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Security\Permission;
use App\Erp\Settings\Setting;
use App\Livewire\Concerns\GuardsModelAccess;
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

    protected function accessModelKey(): string
    {
        return 'limousine.receipt';
    }

    public function mount(): void
    {
        $this->guardAccess(Permission::Read);
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

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $query = LimoReceipt::query()
            ->with(['customer:id,name', 'invoice:id,reference'])
            ->orderByDesc('id');

        $term = trim($this->search);
        if ($term !== '') {
            $query->where(function ($q) use ($term): void {
                $q->where('reference', 'like', "%{$term}%")
                    ->orWhereHas('customer', function ($c) use ($term): void {
                        $c->where('name', 'like', "%{$term}%");
                    });
            });
        }

        return view('limousine::receipts', [
            'receipts' => $query->paginate(20),
            // Money taken on a booking issues its own receipt, so a hand-made
            // one is a correction reserved for the owner. Same rule server-side
            // in ReceiptForm — hiding the button alone would only be cosmetic.
            'canCreateManually' => Auth::user()?->isSuperAdmin() ?? false,
            'collectedTotal' => (float) LimoReceipt::query()->sum('amount'),
        ]);
    }
}
