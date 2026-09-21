{{--
    The logins a business runs on.

    A password is never in the page the browser first receives - each one is
    fetched by a deliberate click and written to the activity log. Entries the
    viewer may not see are excluded by the QUERY, so they never arrive at all.
--}}
<div class="mx-auto max-w-5xl px-4 py-6 sm:px-6" x-data x-on:beforeunload.window="$wire.hideAll()">

    <div class="mb-6 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-chrome-900">{{ __('Saved logins') }}</h1>
            <p class="mt-1 text-sm text-chrome-500">
                {{ __('The logins this business runs on. Stored encrypted, and every reveal is recorded.') }}
            </p>
        </div>
        <button type="button" wire:click="create" class="o-btn-primary rounded-lg px-4 py-2 text-sm font-semibold">
            {{ __('Add a login') }}
        </button>
    </div>

    @if (session()->has('vault-saved'))
        <div class="mb-4 rounded-xl bg-emerald-50 px-4 py-2.5 text-sm font-medium text-emerald-700 ring-1 ring-emerald-100">
            {{ session('vault-saved') }}
        </div>
    @endif

    <div class="mb-4">
        <input type="search" wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Search by service, username or address…') }}" class="o-input w-full sm:w-80" />
    </div>

    <div class="space-y-3">
        @forelse ($entries as $entry)
            @php $shown = $revealed[$entry->id] ?? null; @endphp
            <div class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/[0.06]">

                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="font-semibold text-chrome-900">{{ $entry->name }}</h2>
                            @if ($entry->owner_only)
                                <span class="rounded-full bg-chrome-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-chrome-500">{{ __('Owner only') }}</span>
                            @else
                                <span class="rounded-full bg-sky-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-sky-700 ring-1 ring-sky-100">{{ __('Shared with admins') }}</span>
                            @endif
                        </div>
                        @if ($entry->linkUrl() !== null)
                            {{-- noopener: the target must not get a handle on this tab. --}}
                            <a href="{{ $entry->linkUrl() }}" target="_blank" rel="noopener noreferrer"
                                class="mt-0.5 block truncate text-xs font-medium text-primary-700 hover:underline">{{ $entry->url }}</a>
                        @endif
                    </div>
                    <div class="flex shrink-0 items-center gap-1">
                        <button type="button" wire:click="edit({{ $entry->id }})"
                            class="rounded-lg px-2.5 py-1 text-xs font-semibold text-chrome-500 transition hover:bg-chrome-100 hover:text-chrome-800">{{ __('Edit') }}</button>
                        <button type="button" wire:click="delete({{ $entry->id }})"
                            wire:confirm="{{ __('Delete :name? This cannot be undone.', ['name' => $entry->name]) }}"
                            class="rounded-lg px-2.5 py-1 text-xs font-semibold text-red-600 transition hover:bg-red-50">{{ __('Delete') }}</button>
                    </div>
                </div>

                <dl class="mt-3 grid gap-2 sm:grid-cols-2">
                    <div>
                        <dt class="text-[11px] font-bold uppercase tracking-wide text-chrome-400">{{ __('Username') }}</dt>
                        <dd class="mt-0.5 flex items-center gap-2">
                            <span class="truncate font-mono text-sm text-chrome-800">{{ $entry->username ?: '—' }}</span>
                            @if ($entry->username)
                                <button type="button" class="shrink-0 text-[11px] font-semibold text-chrome-400 hover:text-chrome-700"
                                    x-on:click="navigator.clipboard.writeText(@js($entry->username)); $el.textContent = @js(__('Copied'))">{{ __('Copy') }}</button>
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-[11px] font-bold uppercase tracking-wide text-chrome-400">{{ __('Password') }}</dt>
                        <dd class="mt-0.5 flex items-center gap-2">
                            @if (! $entry->hasPassword())
                                <span class="text-sm text-chrome-400">—</span>
                            @elseif ($shown === null)
                                {{-- Dots, not the password. The real one is not in
                                     this page until it is asked for. --}}
                                <span class="font-mono text-sm tracking-widest text-chrome-400">••••••••••</span>
                                <button type="button" wire:click="reveal({{ $entry->id }})"
                                    class="shrink-0 text-[11px] font-semibold text-primary-700 hover:underline">{{ __('Reveal') }}</button>
                            @else
                                <span class="truncate font-mono text-sm font-semibold text-chrome-900">{{ $shown }}</span>
                                <button type="button" class="shrink-0 text-[11px] font-semibold text-chrome-400 hover:text-chrome-700"
                                    x-on:click="navigator.clipboard.writeText(@js($shown)); $el.textContent = @js(__('Copied'))">{{ __('Copy') }}</button>
                                <button type="button" wire:click="hide({{ $entry->id }})"
                                    class="shrink-0 text-[11px] font-semibold text-chrome-400 hover:text-chrome-700">{{ __('Hide') }}</button>
                            @endif
                        </dd>
                    </div>
                </dl>

                @if ($entry->note)
                    <div class="mt-3 rounded-lg bg-chrome-50 px-3 py-2">
                        <div class="text-[11px] font-bold uppercase tracking-wide text-chrome-400">{{ __('Note') }}</div>
                        <p class="mt-0.5 whitespace-pre-line text-sm text-chrome-700">{{ $entry->note }}</p>
                    </div>
                @endif

                @if ($entry->updated_by)
                    <p class="mt-2 text-[11px] text-chrome-400">
                        {{ __('Last changed by :who on :when', [
                            'who' => $entry->updated_by,
                            'when' => $entry->updated_at?->isoFormat('D MMM YYYY') ?? '—',
                        ]) }}
                    </p>
                @endif
            </div>
        @empty
            <div class="rounded-2xl bg-white p-10 text-center shadow-sm ring-1 ring-chrome-900/[0.06]">
                <p class="text-sm text-chrome-400">
                    @if ($search !== '')
                        {{ __('Nothing matches that.') }}
                    @elseif ($isOwner)
                        {{ __('Nothing saved yet. Add the first login and stop keeping them in a spreadsheet.') }}
                    @else
                        {{ __('No logins have been shared with admins here.') }}
                    @endif
                </p>
            </div>
        @endforelse
    </div>

    <p class="mt-6 text-[11px] leading-snug text-chrome-400">
        {{ __('Passwords and notes are encrypted in this database, and every reveal is written to the activity log. The key lives on this server, so treat this as safe from a stolen database file but not as a replacement for a dedicated password manager for banking.') }}
    </p>

    {{-- Add / edit --}}
    @if ($editing)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-chrome-900/50 p-4"
            x-on:keydown.escape.window="$wire.cancel()">
            <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-6 shadow-xl">
                <h3 class="text-lg font-bold text-chrome-900">
                    {{ $editingId === null ? __('Add a login') : __('Edit login') }}
                </h3>

                <div class="mt-5 space-y-4">
                    <div>
                        <label for="v-name" class="block text-sm font-medium text-chrome-700">{{ __('Service') }}</label>
                        <input id="v-name" type="text" wire:model="name" class="o-input mt-1 w-full"
                            placeholder="{{ __('WooCommerce, hosting, Instagram…') }}" />
                        @error('name')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="v-url" class="block text-sm font-medium text-chrome-700">{{ __('Address') }}</label>
                        <input id="v-url" type="text" wire:model="url" class="o-input mt-1 w-full" placeholder="wanaan-bh.com/wp-admin" />
                        @error('url')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="v-user" class="block text-sm font-medium text-chrome-700">{{ __('Username') }}</label>
                        <input id="v-user" type="text" wire:model="username" autocomplete="off" class="o-input mt-1 w-full" />
                        @error('username')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <div class="flex items-center justify-between">
                            <label for="v-pass" class="block text-sm font-medium text-chrome-700">{{ __('Password') }}</label>
                            <button type="button" wire:click="generate" class="text-[11px] font-semibold text-primary-700 hover:underline">{{ __('Generate a strong one') }}</button>
                        </div>
                        {{-- type=text on purpose: whoever is adding it needs to
                             check it against the site they just set it on. --}}
                        <input id="v-pass" type="text" wire:model="password" autocomplete="off" spellcheck="false"
                            class="o-input mt-1 w-full font-mono"
                            placeholder="{{ $editingId === null ? '' : __('Leave blank to keep the current one') }}" />
                        @error('password')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                    </div>

                    <div>
                        <label for="v-note" class="block text-sm font-medium text-chrome-700">{{ __('Note') }}</label>
                        <textarea id="v-note" wire:model="note" rows="3" class="o-input mt-1 w-full"
                            placeholder="{{ __('Recovery codes, which email it is tied to, who to call…') }}"></textarea>
                        <p class="mt-1 text-[11px] text-chrome-400">{{ __('Encrypted too, so recovery codes belong here.') }}</p>
                        @error('note')<p class="mt-1 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
                    </div>

                    @if ($isOwner)
                        <label class="flex items-start gap-2.5 rounded-lg bg-chrome-50 px-3 py-2.5">
                            <input type="checkbox" wire:model="ownerOnly" class="mt-0.5 rounded border-chrome-300" />
                            <span>
                                <span class="block text-sm font-medium text-chrome-800">{{ __('Owner only') }}</span>
                                <span class="block text-[11px] leading-snug text-chrome-500">
                                    {{ __('Untick to let this database\'s admins reveal it — useful for a social account, wrong for a bank.') }}
                                </span>
                            </span>
                        </label>
                    @endif
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" wire:click="cancel"
                        class="rounded-lg px-4 py-2 text-sm font-semibold text-chrome-600 transition hover:bg-chrome-100">{{ __('Cancel') }}</button>
                    <button type="button" wire:click="save" wire:loading.attr="disabled"
                        class="o-btn-primary rounded-lg px-4 py-2 text-sm font-semibold">{{ __('Save') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>
