<form wire:submit="save" class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-chrome-900">{{ __('Set a new password') }}</h2>
        <p class="text-sm text-chrome-500">{{ __('Choose a password you have not used before.') }}</p>
    </div>

    @if ($email !== '')
        <p class="rounded-lg bg-chrome-50 px-3 py-2 text-sm text-chrome-600">
            {{ __('You are setting the password for :email', ['email' => $email]) }}
        </p>
        @error('email') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
    @else
        <div>
            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Email') }}</label>
            <input type="email" wire:model="email" autocomplete="email" class="o-input">
            @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
    @endif

    <div x-data="{ show: false, blocked: false, timer: null, notifyBlocked() { this.blocked = true; clearTimeout(this.timer); this.timer = setTimeout(() => this.blocked = false, 2500) } }">
        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('New password') }}</label>
        <div class="relative">
            <input :type="show ? 'text' : 'password'" wire:model="password"
                autocomplete="new-password" class="o-input pe-10"
                x-on:beforeinput="if ($event.data && /[^\x20-\x7E]/.test($event.data)) { $event.preventDefault(); notifyBlocked() }">
            <button type="button" @click="show = !show" tabindex="-1"
                :aria-label="show ? @js(__('Hide password')) : @js(__('Show password'))"
                class="absolute inset-y-0 end-0 flex items-center px-3 text-primary-600 hover:text-primary-700">
                <svg x-show="!show" class="size-5" viewBox="0 0 20 20" fill="currentColor">
                    <path d="M10 4C5.5 4 2.4 7.4 1.3 9.3a1.4 1.4 0 0 0 0 1.4C2.4 12.6 5.5 16 10 16s7.6-3.4 8.7-5.3a1.4 1.4 0 0 0 0-1.4C17.6 7.4 14.5 4 10 4Zm0 9a3 3 0 1 1 0-6 3 3 0 0 1 0 6Z"/>
                </svg>
                <svg x-show="show" x-cloak class="size-5" viewBox="0 0 20 20" fill="currentColor">
                    <path d="M3.7 2.3A1 1 0 0 0 2.3 3.7l2 2C3 6.8 1.9 8.2 1.3 9.3a1.4 1.4 0 0 0 0 1.4C2.4 12.6 5.5 16 10 16c1.5 0 2.9-.4 4.1-1l2.2 2.2a1 1 0 0 0 1.4-1.4l-14-13.5ZM10 13a3 3 0 0 1-2.8-4.1l3.9 3.9c-.3.1-.7.2-1.1.2Zm0-9c4.5 0 7.6 3.4 8.7 5.3.3.5.3 1 0 1.4-.5.8-1.2 1.8-2.2 2.7l-2.6-2.6A3 3 0 0 0 8.2 6.6L6.4 4.8C7.5 4.3 8.7 4 10 4Z"/>
                </svg>
            </button>
        </div>
        <x-password-ascii-notice />
        @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        <p class="mt-1 text-xs text-chrome-400">{{ __('At least 8 characters.') }}</p>
    </div>

    <div x-data="{ show: false, blocked: false, timer: null, notifyBlocked() { this.blocked = true; clearTimeout(this.timer); this.timer = setTimeout(() => this.blocked = false, 2500) } }">
        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Confirm new password') }}</label>
        <div class="relative">
            <input :type="show ? 'text' : 'password'" wire:model="passwordConfirmation"
                autocomplete="new-password" class="o-input pe-10"
                x-on:beforeinput="if ($event.data && /[^\x20-\x7E]/.test($event.data)) { $event.preventDefault(); notifyBlocked() }">
            <button type="button" @click="show = !show" tabindex="-1"
                :aria-label="show ? @js(__('Hide password')) : @js(__('Show password'))"
                class="absolute inset-y-0 end-0 flex items-center px-3 text-primary-600 hover:text-primary-700">
                <svg x-show="!show" class="size-5" viewBox="0 0 20 20" fill="currentColor">
                    <path d="M10 4C5.5 4 2.4 7.4 1.3 9.3a1.4 1.4 0 0 0 0 1.4C2.4 12.6 5.5 16 10 16s7.6-3.4 8.7-5.3a1.4 1.4 0 0 0 0-1.4C17.6 7.4 14.5 4 10 4Zm0 9a3 3 0 1 1 0-6 3 3 0 0 1 0 6Z"/>
                </svg>
                <svg x-show="show" x-cloak class="size-5" viewBox="0 0 20 20" fill="currentColor">
                    <path d="M3.7 2.3A1 1 0 0 0 2.3 3.7l2 2C3 6.8 1.9 8.2 1.3 9.3a1.4 1.4 0 0 0 0 1.4C2.4 12.6 5.5 16 10 16c1.5 0 2.9-.4 4.1-1l2.2 2.2a1 1 0 0 0 1.4-1.4l-14-13.5ZM10 13a3 3 0 0 1-2.8-4.1l3.9 3.9c-.3.1-.7.2-1.1.2Zm0-9c4.5 0 7.6 3.4 8.7 5.3.3.5.3 1 0 1.4-.5.8-1.2 1.8-2.2 2.7l-2.6-2.6A3 3 0 0 0 8.2 6.6L6.4 4.8C7.5 4.3 8.7 4 10 4Z"/>
                </svg>
            </button>
        </div>
        <x-password-ascii-notice />
    </div>

    <button type="submit" class="o-btn-primary w-full justify-center">
        <span wire:loading.remove wire:target="save">{{ __('Change password') }}</span>
        <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
    </button>

    <a href="{{ route('login') }}" wire:navigate
        class="block text-center text-sm font-medium text-primary-700 hover:text-primary-800 hover:underline">
        {{ __('Back to sign in') }}
    </a>
</form>
