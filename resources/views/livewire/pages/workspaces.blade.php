<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <div class="mb-5">
        <h1 class="text-xl font-bold text-chrome-900">{{ __('My databases') }}</h1>
        <p class="text-sm text-chrome-500">
            {{ __('Each database is a separate, isolated ERP with all features. Switch between them any time — your Main database is your current data.') }}
        </p>
    </div>

    @if (session('workspace_status'))
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200">
            {{ session('workspace_status') }}
        </div>
    @endif

    {{-- Create --}}
    <div class="mb-6 rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
        <h2 class="text-sm font-semibold text-chrome-800">{{ __('Create a new database') }}</h2>
        <p class="mt-1 text-xs text-chrome-400">{{ __('A fresh ERP with all modules installed and your admin login. Takes a few seconds to build.') }}</p>
        <form wire:submit="create" class="mt-3 flex flex-wrap gap-2">
            <input type="text" wire:model="newName" placeholder="{{ __('e.g. Second branch') }}"
                class="o-input max-w-xs text-sm" autocomplete="off" wire:loading.attr="disabled" wire:target="create">
            <button type="submit" class="o-btn-primary text-sm" wire:loading.attr="disabled" wire:target="create">
                <span wire:loading.remove wire:target="create">{{ __('Create database') }}</span>
                <span wire:loading wire:target="create">{{ __('Building…') }}</span>
            </button>
        </form>
        @error('newName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    {{-- List --}}
    <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
        <ul class="divide-y divide-chrome-100">
            @foreach ($workspaces as $workspace)
                <li wire:key="ws-{{ $workspace->id }}" class="flex items-center justify-between gap-3 px-5 py-3">
                    <div class="flex min-w-0 flex-1 items-center gap-3">
                        <span @class([
                            'flex size-9 shrink-0 items-center justify-center rounded-lg',
                            'bg-primary-400 text-chrome-900' => $workspace->id === $currentId,
                            'bg-chrome-100 text-chrome-500' => $workspace->id !== $currentId,
                        ])>
                            <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 1c-3.866 0-7 1.343-7 3v12c0 1.657 3.134 3 7 3s7-1.343 7-3V4c0-1.657-3.134-3-7-3Zm5 15c0 .35-1.793 1.5-5 1.5S5 16.35 5 16v-2.05c1.298.66 3.107 1.05 5 1.05s3.702-.39 5-1.05V16Zm0-4c0 .35-1.793 1.5-5 1.5S5 12.35 5 12V9.95C6.298 10.61 8.107 11 10 11s3.702-.39 5-1.05V12Zm-5-3C6.793 9 5 7.85 5 7.5V5.95C6.298 6.61 8.107 7 10 7s3.702-.39 5-1.05V7.5c0 .35-1.793 1.5-5 1.5Z"/></svg>
                        </span>

                        @if ($editingId === $workspace->id)
                            {{-- Inline rename --}}
                            <div class="min-w-0 flex-1">
                                <form wire:submit="rename" class="flex flex-wrap items-center gap-2">
                                    <input type="text" wire:model="editName" autocomplete="off"
                                        x-init="$nextTick(() => $el.focus())" @keydown.escape="$wire.cancelRename()"
                                        class="o-input max-w-xs text-sm">
                                    <button type="submit" class="o-btn-primary text-xs">{{ __('Save') }}</button>
                                    <button type="button" wire:click="cancelRename" class="text-xs text-chrome-500 hover:underline">{{ __('Cancel') }}</button>
                                </form>
                                @error('editName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                        @else
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-chrome-800">
                                    {{ $workspace->name }}
                                    @if ($workspace->is_main)
                                        <span class="ms-1 rounded bg-chrome-100 px-1.5 py-0.5 text-[10px] font-medium text-chrome-500">{{ __('Main') }}</span>
                                    @endif
                                </p>
                                <p class="text-xs text-chrome-400">
                                    @if ($workspace->id === $currentId)
                                        <span class="font-medium text-emerald-600">{{ __('Active') }}</span>
                                    @else
                                        {{ $workspace->is_main ? __('Your current data') : __('Separate database') }}
                                    @endif
                                </p>
                            </div>
                        @endif
                    </div>

                    @if ($editingId !== $workspace->id)
                        <div class="flex shrink-0 items-center gap-3">
                            @if ($workspace->id === $currentId)
                                <span class="o-chip bg-emerald-50 text-emerald-600">{{ __('In use') }}</span>
                            @else
                                <a href="{{ url('/workspaces/switch/' . $workspace->id) }}" class="o-btn-primary text-xs">{{ __('Switch') }}</a>
                            @endif
                            <button type="button" wire:click="startRename({{ $workspace->id }})"
                                class="text-xs text-chrome-500 hover:underline">{{ __('rename') }}</button>
                            @unless ($workspace->is_main)
                                <button type="button" wire:click="confirmDelete({{ $workspace->id }})"
                                    class="text-xs text-red-600 hover:underline">{{ __('delete') }}</button>
                            @endunless
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>

    {{-- Recently deleted (trash) — restorable until the retention window ends. --}}
    @if ($trashed->isNotEmpty())
        <div class="mt-6 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
            <div class="border-b border-chrome-100 px-5 py-3">
                <h2 class="text-sm font-semibold text-chrome-800">{{ __('Recently deleted') }}</h2>
                <p class="text-xs text-chrome-400">{{ __('Restorable for :days days, then permanently deleted.', ['days' => $retentionDays]) }}</p>
            </div>
            <ul class="divide-y divide-chrome-100">
                @foreach ($trashed as $ws)
                    @php
                        $expiry = $ws->deleted_at?->copy()->addDays($retentionDays);
                        $daysLeft = $expiry ? max(0, (int) floor(now()->diffInDays($expiry, false))) : 0;
                    @endphp
                    <li wire:key="trash-{{ $ws->id }}" class="flex items-center justify-between gap-3 px-5 py-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-red-50 text-red-400">
                                <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M9 2a1 1 0 0 0-.894.553L7.382 4H4a1 1 0 0 0 0 2v10a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V6a1 1 0 1 0 0-2h-3.382l-.724-1.447A1 1 0 0 0 11 2H9ZM7 8a1 1 0 0 1 2 0v6a1 1 0 1 1-2 0V8Zm5-1a1 1 0 0 0-1 1v6a1 1 0 1 0 2 0V8a1 1 0 0 0-1-1Z" clip-rule="evenodd" /></svg>
                            </span>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-chrome-700">{{ $ws->name }}</p>
                                <p class="text-xs text-chrome-400">
                                    {{ $daysLeft > 0 ? __(':days days left to restore', ['days' => $daysLeft]) : __('Deletes today') }}
                                </p>
                            </div>
                        </div>
                        <button type="button" wire:click="restoreWorkspace({{ $ws->id }})" class="o-btn-ghost text-xs">{{ __('Restore') }}</button>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <p class="mt-4 text-xs text-chrome-400">
        {{ __('Tip: switching changes the whole app to that database. Come back here to switch to Main.') }}
    </p>

    {{-- Delete confirmation: requires the admin's password; moves the DB to trash. --}}
    @if ($deletingId !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            x-data x-init="$nextTick(() => $refs.pw?.focus())" @keydown.escape.window="$wire.cancelDelete()">
            <div class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl" @click.outside="$wire.cancelDelete()">
                <h3 class="text-base font-semibold text-chrome-900">{{ __('Delete database') }}</h3>
                <p class="mt-1 text-sm text-chrome-500">
                    {{ __('It will be moved to trash and permanently deleted after :days days. You can restore it before then. Enter your password to confirm.', ['days' => $retentionDays]) }}
                </p>
                <form wire:submit="deleteWorkspace" class="mt-4" x-data="{ show: false }">
                    <label class="block text-xs font-medium text-chrome-500">{{ __('Your password') }}</label>
                    <div class="relative mt-1">
                        <input x-ref="pw" :type="show ? 'text' : 'password'" wire:model="deletePassword"
                            autocomplete="current-password" class="o-input w-full pe-9 text-sm">
                        <button type="button" @click="show = !show" tabindex="-1"
                            class="absolute inset-y-0 end-0 flex items-center pe-3 text-chrome-400 hover:text-chrome-600">
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                        </button>
                    </div>
                    @error('deletePassword') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" wire:click="cancelDelete" class="o-btn-ghost text-sm">{{ __('Cancel') }}</button>
                        <button type="submit" class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
                            {{ __('Move to trash') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- 2FA: regular admins confirm an emailed code before rename/delete. --}}
    @include('partials.otp-modal')
</div>
