<div class="mx-auto max-w-3xl p-6">
    <div class="mb-6">
        <h1 class="text-xl font-bold text-chrome-900">{{ __('Profile') }}</h1>
        <p class="text-sm text-chrome-500">{{ __('Manage your name, photo, email and password.') }}</p>
    </div>

    {{-- Success / info flash from save() or the verification controller. --}}
    @if ($flash)
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ $flash }}
        </div>
    @endif

    {{-- Pending email banner — visible until either the user clicks the
         verification link or cancels here. --}}
    @if ($pendingEmail)
        <div class="mb-4 flex items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <span>
                {{ __('Pending email change:') }} <span class="font-semibold">{{ $pendingEmail }}</span>.
                {{ __('Check that inbox for a verification link (expires in 1 hour).') }}
            </span>
            <button type="button" wire:click="cancelPendingEmailChange"
                class="shrink-0 text-xs font-medium text-amber-900 underline hover:text-amber-700">{{ __('Cancel') }}</button>
        </div>
    @endif

    <form wire:submit.prevent="save" class="space-y-6 rounded-xl bg-white p-6 shadow-sm ring-1 ring-chrome-900/5">
        {{-- Avatar — preview on the left, upload picker on the right. --}}
        <div class="flex items-start gap-5">
            <div class="size-20 shrink-0 overflow-hidden rounded-full bg-chrome-100 ring-1 ring-chrome-200">
                @if ($avatar)
                    {{-- Livewire's temporary upload exposes a public URL via temporaryUrl(). --}}
                    <img src="{{ $avatar->temporaryUrl() }}" alt="Preview" class="size-full object-cover">
                @elseif ($avatarUrl)
                    <img src="{{ $avatarUrl }}" alt="{{ $user->name }}" class="size-full object-cover">
                @else
                    <div class="flex size-full items-center justify-center text-xl font-semibold text-chrome-400">
                        {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($user->name, 0, 1)) }}
                    </div>
                @endif
            </div>
            <div class="flex-1">
                <label class="block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Avatar') }}</label>
                <input type="file" wire:model="avatar" accept="image/*"
                    class="mt-1 block w-full text-sm text-chrome-700 file:me-3 file:rounded-md file:border-0 file:bg-primary-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-primary-700 hover:file:bg-primary-100">
                <p class="mt-1 text-xs text-chrome-400">{{ __('PNG, JPG, WEBP or GIF. Up to 4 MB.') }}</p>
                @error('avatar') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Name --}}
        <div>
            <label class="block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Name') }}</label>
            <input wire:model="name" type="text" class="o-input mt-1 text-sm">
            @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        {{-- Email — typing a different value triggers the verification flow. --}}
        <div>
            <label class="block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Email') }}</label>
            <input wire:model="email" type="email" autocomplete="email" class="o-input mt-1 text-sm">
            <p class="mt-1 text-xs text-chrome-400">
                {{ __('Changing this sends a verification link to the new address. Your current email stays active until you click it.') }}
            </p>
            @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        {{-- Role — read-only display only. The actual rights live in groups
             managed by an administrator via the user resource (out of scope
             for self-service). --}}
        <div>
            <label class="block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Role') }}</label>
            <input type="text" value="{{ __($roleLabel) }}" disabled
                class="o-input mt-1 cursor-not-allowed bg-chrome-100 text-sm text-chrome-500">
            <p class="mt-1 text-xs text-chrome-400">
                {{ __('Roles can only be changed by an administrator.') }}
            </p>
        </div>

        {{-- Password (optional) --}}
        <div class="border-t border-chrome-100 pt-5">
            <h2 class="text-sm font-semibold text-chrome-800">{{ __('Change password') }}</h2>
            <p class="text-xs text-chrome-400">{{ __('Leave blank to keep your current password.') }}</p>

            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                {{-- Each block lifts its own x-data wrapper so `show` (eye
                     toggle) and `blocked` (Arabic-keystroke notice) live
                     together — the inline notice can read sibling state
                     without polluting a parent scope. `notifyBlocked()`
                     debounces: holding a non-ASCII key extends the visible
                     window 2.5 s past the LAST press, not the first. --}}
                <div class="sm:col-span-2" x-data="{
                        show: false,
                        blocked: false,
                        _t: null,
                        notifyBlocked() {
                            this.blocked = true;
                            clearTimeout(this._t);
                            this._t = setTimeout(() => { this.blocked = false }, 2500);
                        }
                    }">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Current password') }}</label>
                    <div class="relative mt-1">
                        {{-- Passwords are constrained to printable ASCII. The
                             `beforeinput` listener vetoes any keystroke or
                             paste containing a non-ASCII codepoint before
                             the input value is mutated, so the field can
                             never hold an Arabic (or other non-Latin)
                             character. Server-side regex on `newPassword`
                             enforces the same rule for JS-disabled clients
                             and crafted Livewire payloads. --}}
                        <input wire:model="currentPassword" :type="show ? 'text' : 'password'"
                            x-on:beforeinput="if ($event.data && /[^\x20-\x7E]/.test($event.data)) { $event.preventDefault(); notifyBlocked() }"
                            autocomplete="current-password" class="o-input pe-10 text-sm">
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
                    @error('currentPassword') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div x-data="{
                        show: false,
                        blocked: false,
                        _t: null,
                        notifyBlocked() {
                            this.blocked = true;
                            clearTimeout(this._t);
                            this._t = setTimeout(() => { this.blocked = false }, 2500);
                        }
                    }">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('New password') }}</label>
                    <div class="relative mt-1">
                        <input wire:model="newPassword" :type="show ? 'text' : 'password'"
                            x-on:beforeinput="if ($event.data && /[^\x20-\x7E]/.test($event.data)) { $event.preventDefault(); notifyBlocked() }"
                            autocomplete="new-password" class="o-input pe-10 text-sm">
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
                    @error('newPassword') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div x-data="{
                        show: false,
                        blocked: false,
                        _t: null,
                        notifyBlocked() {
                            this.blocked = true;
                            clearTimeout(this._t);
                            this._t = setTimeout(() => { this.blocked = false }, 2500);
                        }
                    }">
                    <label class="block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Confirm new password') }}</label>
                    <div class="relative mt-1">
                        <input wire:model="newPasswordConfirmation" :type="show ? 'text' : 'password'"
                            x-on:beforeinput="if ($event.data && /[^\x20-\x7E]/.test($event.data)) { $event.preventDefault(); notifyBlocked() }"
                            autocomplete="new-password" class="o-input pe-10 text-sm">
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
            </div>
        </div>

        <div class="flex justify-end gap-2 border-t border-chrome-100 pt-5">
            <button type="submit" class="o-btn-primary">
                <span wire:loading.remove>{{ __('Save changes') }}</span>
                <span wire:loading>{{ __('Saving…') }}</span>
            </button>
        </div>
    </form>
</div>
