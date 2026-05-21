<form wire:submit="login" class="space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-chrome-900">{{ __('Sign in') }}</h2>
        <p class="text-sm text-chrome-500">{{ __('Use your OpenERP credentials.') }}</p>
    </div>

    <div>
        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Email or username') }}</label>
        <input type="text" wire:model="email" autofocus autocomplete="username" class="o-input">
        @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <div>
        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Password') }}</label>
        <div class="relative" x-data="{ show: false }">
            <input :type="show ? 'text' : 'password'" wire:model="password"
                autocomplete="current-password" class="o-input pe-10">
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
        @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    <label class="flex items-center gap-2 text-sm text-chrome-600">
        <input type="checkbox" wire:model="remember"
            class="rounded border-chrome-300 text-primary-600 focus:ring-primary-500">
        {{ __('Remember me') }}
    </label>

    <button type="submit" class="o-btn-primary w-full justify-center">
        <span wire:loading.remove wire:target="login">{{ __('Sign in') }}</span>
        <span wire:loading wire:target="login">{{ __('Signing in…') }}</span>
    </button>
</form>
