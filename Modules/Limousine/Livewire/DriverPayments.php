<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire;

use App\Erp\Activity\ActivityLogger;
use App\Erp\Security\Permission;
use App\Erp\Settings\Setting;
use App\Erp\Views\ValueFormat;
use App\Livewire\Concerns\GuardsModelAccess;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoDriverPayment;
use Modules\Limousine\Support\DriverBalances;

/**
 * Driver payments: what each outside driver is owed, paying them (in full or
 * any amount), and every payment made — date, amount, who asked for it and
 * who recorded it.
 *
 * Seen by the same people as the Outside drivers page. Paying is for the
 * owner and the accountants; the person who asked for a payment is chosen
 * from the staff the owner allows (Ali and Abbas to begin with). A payment is
 * cancelled with a reason by the owner, never deleted.
 */
#[Layout('components.layouts.app')]
#[Title('Driver payments')]
final class DriverPayments extends Component
{
    use GuardsModelAccess;
    use WithPagination;

    #[Url(as: 'driver', except: 0)]
    public int $driverFilter = 0;

    /** The payment form: the driver being paid, or null when closed. */
    #[Locked]
    public ?int $payingDriverId = null;

    public string $amount = '';

    public string $paidOn = '';

    public string $requestedBy = '';

    public string $method = 'cash';

    public string $note = '';

    /** Set when the amount is more than is owed, so it is paid on purpose. */
    public bool $confirmAhead = false;

    #[Locked]
    public ?int $cancellingId = null;

    public string $cancelReason = '';

    public string $startDate = '';

    protected function accessModelKey(): string
    {
        return 'limousine.driver';
    }

    public function mount(): void
    {
        $this->guardAccess(Permission::Read);
        $this->guardView();
        $this->startDate = DriverBalances::start()->toDateString();

        // "Pay" on the Outside drivers page lands here with the form open.
        $pay = request()->query('pay');
        if (is_string($pay) && ctype_digit($pay) && $this->mayPay()) {
            $this->openPay((int) $pay);
        }
    }

    private function me(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    private function guardView(): void
    {
        abort_unless(OutsideDrivers::roleMayView($this->me()), 403);
    }

    /** The owner and the accountants record payments. */
    private function mayPay(): bool
    {
        return $this->me()?->canConfirmPayments() === true;
    }

    private function isOwner(): bool
    {
        return $this->me()?->isSuperAdmin() === true;
    }

    /** @return Collection<int, User> */
    private function requesters(): Collection
    {
        return User::query()->where('can_request_driver_payments', true)->orderBy('name')->get(['id', 'name']);
    }

    public function openPay(int $driverId): void
    {
        $this->guardView();
        abort_unless($this->mayPay(), 403);

        if (! LimoDriver::query()->whereKey($driverId)->exists()) {
            return;
        }

        $this->payingDriverId = $driverId;
        $owed = app(DriverBalances::class)->of($driverId)['owed'] ?? 0.0;
        $this->amount = $owed > 0 ? $this->plain($owed) : '';
        $this->paidOn = Carbon::now()->toDateString();
        $this->requestedBy = '';
        $this->method = 'cash';
        $this->note = '';
        $this->confirmAhead = false;
        $this->resetValidation();
    }

    /** "Pay full": the whole amount owed today. */
    public function payFull(): void
    {
        if ($this->payingDriverId === null) {
            return;
        }

        $owed = app(DriverBalances::class)->of($this->payingDriverId)['owed'] ?? 0.0;
        $this->amount = $owed > 0 ? $this->plain($owed) : '';
        $this->confirmAhead = false;
    }

    public function closePay(): void
    {
        $this->payingDriverId = null;
    }

    public function savePay(ActivityLogger $activity): void
    {
        $this->guardView();
        abort_unless($this->mayPay(), 403);

        if ($this->payingDriverId === null) {
            return;
        }

        $requesterIds = $this->requesters()->modelKeys();

        $this->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'paidOn' => ['required', 'date', 'before_or_equal:today'],
            'requestedBy' => ['required', 'in:' . implode(',', $requesterIds === [] ? ['none'] : $requesterIds)],
            'method' => ['required', 'in:' . implode(',', LimoDriverPayment::METHODS)],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'requestedBy.required' => __('Choose who asked for this payment.'),
            'requestedBy.in' => __('Choose who asked for this payment.'),
            'paidOn.before_or_equal' => __('A payment cannot be dated in the future.'),
        ]);

        $amount = round((float) $this->amount, 3);
        $owed = app(DriverBalances::class)->of($this->payingDriverId)['owed'] ?? 0.0;
        if ($amount > $owed + 0.0005 && ! $this->confirmAhead) {
            $this->addError('amount', __('That is more than the :owed owed. Tick the box below to pay ahead on purpose.', ['owed' => ValueFormat::money(max(0.0, $owed))]));

            return;
        }

        $payment = LimoDriverPayment::query()->create([
            'driver_id' => $this->payingDriverId,
            'amount' => $amount,
            'paid_on' => $this->paidOn,
            'method' => $this->method,
            'requested_by_user_id' => (int) $this->requestedBy,
            'recorded_by_user_id' => $this->me()?->id,
            'note' => trim($this->note) !== '' ? trim($this->note) : null,
        ]);

        $activity->logFor($payment, 'created', __('Paid :amount to :driver', [
            'amount' => ValueFormat::money($amount),
            'driver' => (string) ($payment->driver->name ?? ''),
        ]));

        $this->payingDriverId = null;
        session()->flash('toast', __('Payment :reference saved.', ['reference' => (string) $payment->fresh()?->reference]));
    }

    public function openCancel(int $id): void
    {
        $this->guardView();
        abort_unless($this->isOwner(), 403);

        $this->cancellingId = $id;
        $this->cancelReason = '';
        $this->resetValidation();
    }

    public function closeCancel(): void
    {
        $this->cancellingId = null;
    }

    /** A payment made by mistake is cancelled with a reason, never deleted. */
    public function confirmCancel(ActivityLogger $activity): void
    {
        $this->guardView();
        abort_unless($this->isOwner(), 403);

        $payment = $this->cancellingId !== null ? LimoDriverPayment::query()->find($this->cancellingId) : null;
        if ($payment === null || $payment->isCancelled()) {
            $this->cancellingId = null;

            return;
        }

        $this->validate(['cancelReason' => ['required', 'string', 'max:500']], ['cancelReason.required' => __('Say why the payment is cancelled.')]);

        $payment->forceFill([
            'cancelled_at' => Carbon::now(),
            'cancelled_by_user_id' => $this->me()?->id,
            'cancel_reason' => trim($this->cancelReason),
        ])->save();

        $activity->logFor($payment, 'updated', __('Payment cancelled: :reason', ['reason' => trim($this->cancelReason)]));
        $this->cancellingId = null;
    }

    /** The owner: from when trips count towards what is owed. */
    public function saveStart(): void
    {
        $this->guardView();
        abort_unless($this->isOwner(), 403);

        $this->validate(['startDate' => ['required', 'date', 'before_or_equal:today']]);
        Setting::set(DriverBalances::START_KEY, Carbon::parse($this->startDate)->toDateString());
        session()->flash('toast', __('Saved.'));
    }

    /** The owner: who may be named as having asked for a payment. */
    public function toggleRequester(int $userId): void
    {
        $this->guardView();
        abort_unless($this->isOwner(), 403);

        $user = User::query()->find($userId);
        if ($user !== null) {
            $user->forceFill(['can_request_driver_payments' => ! ((bool) $user->getAttribute('can_request_driver_payments'))])->save();
        }
    }

    public function updatedDriverFilter(): void
    {
        $this->resetPage();
    }

    private function plain(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    public function render(): View
    {
        $balances = app(DriverBalances::class)->all();
        $payments = LimoDriverPayment::query()
            ->with(['driver:id,name', 'requestedBy:id,name', 'recordedBy:id,name', 'cancelledBy:id,name'])
            ->when($this->driverFilter > 0, fn ($q) => $q->where('driver_id', $this->driverFilter))
            ->orderByDesc('paid_on')
            ->orderByDesc('id')
            ->paginate(25);

        $paying = $this->payingDriverId !== null ? ($balances[$this->payingDriverId] ?? null) : null;

        return view('limousine::driver-payments', [
            'balances' => $balances,
            'totalOwed' => round((float) array_sum(array_map(static fn (array $b): float => max(0.0, $b['owed']), $balances)), 3),
            'totalAhead' => round((float) array_sum(array_column($balances, 'ahead')), 3),
            'payments' => $payments,
            'paying' => $paying,
            'payingName' => $this->payingDriverId !== null ? (string) (LimoDriver::query()->whereKey($this->payingDriverId)->value('name') ?? '') : '',
            'requesters' => $this->requesters(),
            'methods' => LimoDriverPayment::METHODS,
            'mayPay' => $this->mayPay(),
            'isOwner' => $this->isOwner(),
            'start' => DriverBalances::start(),
            'staff' => $this->isOwner() ? User::query()->orderBy('name')->get(['id', 'name', 'can_request_driver_payments']) : collect(),
            'driverOptions' => LimoDriver::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
