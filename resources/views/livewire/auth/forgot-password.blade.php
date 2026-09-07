<div>
    @if ($sent)
        <div class="space-y-4">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">
                    <svg class="size-5" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M2.5 5.5A2 2 0 0 1 4.5 4h11a2 2 0 0 1 2 1.5L10 10.6 2.5 5.5Z"/>
                        <path d="M18 7.6v6.9a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V7.6l7.4 5a1 1 0 0 0 1.2 0l7.4-5Z"/>
                    </svg>
                </span>
                <div>
                    <h2 class="text-lg font-semibold text-chrome-900">{{ __('Check your email') }}</h2>
                    <p class="mt-1 text-sm text-chrome-500">
                        {{ __('If that address belongs to an account, a link to set a new password is on its way.') }}
                    </p>
                </div>
            </div>

            <p class="text-xs text-chrome-400">
                {{ __('The link expires in an hour. If it does not arrive, check your spam folder.') }}
            </p>

            <a href="{{ route('login') }}" wire:navigate class="o-btn-primary w-full justify-center">
                {{ __('Back to sign in') }}
            </a>
        </div>
    @else
        <form wire:submit="send" class="space-y-4">
            <div>
                <h2 class="text-lg font-semibold text-chrome-900">{{ __('Forgot your password?') }}</h2>
                <p class="text-sm text-chrome-500">
                    {{ __('Enter your email and we will send you a link to set a new one.') }}
                </p>
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Email') }}</label>
                <input type="email" wire:model="email" autofocus autocomplete="email" class="o-input">
                @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <button type="submit" class="o-btn-primary w-full justify-center">
                <span wire:loading.remove wire:target="send">{{ __('Send reset link') }}</span>
                <span wire:loading wire:target="send">{{ __('Sending…') }}</span>
            </button>

            <a href="{{ route('login') }}" wire:navigate
                class="block text-center text-sm font-medium text-primary-700 hover:text-primary-800 hover:underline">
                {{ __('Back to sign in') }}
            </a>
        </form>
    @endif
</div>
