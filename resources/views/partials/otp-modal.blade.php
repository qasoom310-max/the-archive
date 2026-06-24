{{-- Shared email-OTP confirmation modal. Expects the host component to use
     the App\Livewire\Concerns\ConfirmsWithEmailOtp trait (props $otpOpen /
     $otpCode + submitOtp / cancelOtp). Sits at a high z-index so it overlays
     any other modal (e.g. the database delete-password dialog). --}}
@if ($otpOpen)
    <div class="fixed inset-0 z-[60] flex items-center justify-center p-4"
        x-data x-on:keydown.escape.window="$wire.cancelOtp()"
        x-init="$nextTick(() => $refs.otp && $refs.otp.focus())">
        <div class="absolute inset-0 bg-chrome-900/40" wire:click="cancelOtp"></div>
        <div class="relative w-full max-w-sm rounded-2xl bg-white p-5 shadow-pop ring-1 ring-chrome-900/5">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-base font-bold text-chrome-900">{{ __('Verification required') }}</h2>
                <button type="button" wire:click="cancelOtp" class="text-chrome-400 transition hover:text-chrome-700" title="{{ __('Cancel') }}">✕</button>
            </div>
            <p class="mb-3 text-sm text-chrome-500">
                {{ __('We emailed a 6-digit code to :email. Enter it to confirm this action.', ['email' => auth()->user()?->email]) }}
            </p>
            <input type="text" inputmode="numeric" maxlength="6" x-ref="otp" autocomplete="one-time-code"
                wire:model="otpCode" wire:keydown.enter="submitOtp"
                placeholder="••••••"
                class="o-input w-full text-center text-lg tracking-[0.4em] tabular-nums">
            @error('otpCode') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            <div class="mt-4 flex gap-2">
                <button type="button" wire:click="cancelOtp" class="o-btn-ghost flex-1 justify-center">{{ __('Cancel') }}</button>
                <button type="button" wire:click="submitOtp" wire:loading.attr="disabled" wire:target="submitOtp"
                    class="o-btn-primary flex-1 justify-center">{{ __('Confirm') }}</button>
            </div>
        </div>
    </div>
@endif
