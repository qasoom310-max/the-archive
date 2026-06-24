<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Erp\Security\TwoFactorGate;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Gates a sensitive Livewire action behind an emailed one-time code for
 * REGULAR admins (super admins are exempt and proceed immediately).
 *
 * Flow: a host method calls {@see requireOtp()}. If the actor is exempt it
 * returns true and the host runs the action now. Otherwise a code is emailed,
 * the modal opens, and the host returns; once the admin submits a valid code,
 * {@see submitOtp()} dispatches back to the host via {@see runConfirmedAction()}.
 *
 * The host's form/state props survive the OTP round-trip (Livewire preserves
 * component state), so the deferred action re-reads them — but the host should
 * still re-validate / re-guard inside runConfirmedAction() as defence in depth.
 */
trait ConfirmsWithEmailOtp
{
    public bool $otpOpen = false;

    public string $otpCode = '';

    /** Identifier of the action awaiting confirmation (e.g. 'user.delete'). */
    public string $otpAction = '';

    /** @var array<string, mixed> Arguments to replay into the confirmed action. */
    public array $otpArgs = [];

    /**
     * Gate a sensitive action. Returns true if the caller may run it NOW
     * (exempt actor); false if a code was issued and the action must defer.
     *
     * @param array<string, mixed> $args
     */
    protected function requireOtp(string $action, array $args = []): bool
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return false;
        }

        $gate = app(TwoFactorGate::class);
        if (! $gate->required($user)) {
            return true;
        }

        $gate->challenge($user, $action);

        $this->otpAction = $action;
        $this->otpArgs = $args;
        $this->otpCode = '';
        $this->otpOpen = true;
        $this->resetErrorBag('otpCode');

        return false;
    }

    public function submitOtp(): void
    {
        $user = Auth::user();
        if (! $user instanceof User || ! $this->otpOpen) {
            return;
        }

        if (! app(TwoFactorGate::class)->verify($user, $this->otpAction, $this->otpCode)) {
            $this->addError('otpCode', __('Invalid or expired code. Please try again.'));

            return;
        }

        $action = $this->otpAction;
        $args = $this->otpArgs;
        $this->cancelOtp();

        $this->runConfirmedAction($action, $args);
    }

    public function cancelOtp(): void
    {
        $this->otpOpen = false;
        $this->otpCode = '';
        $this->otpAction = '';
        $this->otpArgs = [];
        $this->resetErrorBag('otpCode');
    }

    /**
     * Run the action the OTP confirmed. The host MUST re-guard/re-validate.
     *
     * @param array<string, mixed> $args
     */
    abstract protected function runConfirmedAction(string $action, array $args): void;
}
