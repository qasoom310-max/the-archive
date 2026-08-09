<div class="mx-auto max-w-6xl p-4 sm:p-6">
    <div class="mb-5">
        <h1 class="text-xl font-bold text-chrome-900">{{ __('Activity log') }}</h1>
        <p class="text-sm text-chrome-500">{{ __('Everything admins and members do, with date and time.') }}</p>
    </div>

    @if (session('activity_toast'))
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-2.5 text-sm font-medium text-emerald-700 ring-1 ring-emerald-100">{{ session('activity_toast') }}</div>
    @endif

    {{-- Backups: this database's daily snapshots + restore --}}
    <div class="mb-6 overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-chrome-100 bg-chrome-50/60 px-4 py-3">
            <div>
                <h2 class="text-sm font-semibold text-chrome-800">{{ __('Backups') }}</h2>
                <p class="text-xs text-chrome-400">{{ __('A daily snapshot of this whole database. Restore any of the last :n days.', ['n' => $retentionDays]) }}</p>
            </div>
            <button type="button" wire:click="backupNow" wire:loading.attr="disabled" wire:target="backupNow"
                class="o-btn-primary shrink-0">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 3a1 1 0 0 1 1 1v6.586l1.293-1.293a1 1 0 1 1 1.414 1.414l-3 3a1 1 0 0 1-1.414 0l-3-3a1 1 0 1 1 1.414-1.414L9 10.586V4a1 1 0 0 1 1-1Z"/><path d="M4 15a1 1 0 0 1 1 1v1h10v-1a1 1 0 1 1 2 0v1a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-1a1 1 0 0 1 1-1Z"/></svg>
                <span wire:loading.remove wire:target="backupNow">{{ __('Back up now') }}</span>
                <span wire:loading wire:target="backupNow">{{ __('Backing up…') }}</span>
            </button>
        </div>
        <div class="px-4 pt-3 pb-1">
            <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 ring-1 ring-amber-100">{{ __('Restoring rolls the ENTIRE database back to that snapshot — anything added, edited, or deleted afterwards is lost. It cannot be undone.') }}</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[560px] text-sm">
                <thead class="text-xs uppercase tracking-wide text-chrome-500">
                    <tr>
                        <th class="px-4 py-2 text-start font-semibold">{{ __('Backup') }}</th>
                        <th class="px-4 py-2 text-end font-semibold">{{ __('Size') }}</th>
                        <th class="px-4 py-2 text-end font-semibold"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-chrome-50">
                    @forelse ($backups as $b)
                        <tr wire:key="backup-{{ $b['name'] }}">
                            <td class="px-4 py-2.5">
                                <div class="font-medium text-chrome-800">{{ $b['created_at']->isoFormat('ddd, MMM D · h:mm A') }}</div>
                                <div class="text-xs text-chrome-400">{{ $b['created_at']->diffForHumans() }}</div>
                            </td>
                            <td class="px-4 py-2.5 text-end tabular-nums text-chrome-600">{{ number_format($b['size'] / 1024, 0) }} KB</td>
                            <td class="px-4 py-2.5">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ url('/app/backups/download?file=' . urlencode($b['path'])) }}"
                                        class="rounded-lg bg-chrome-100 px-2.5 py-1 text-xs font-medium text-chrome-700 hover:bg-chrome-200">{{ __('Download') }}</a>
                                    <button type="button" wire:click="confirmRestore('{{ $b['path'] }}')"
                                        class="rounded-lg bg-primary-400 px-2.5 py-1 text-xs font-semibold text-chrome-900 hover:bg-primary-500">{{ __('Restore') }}</button>
                                    <button type="button" wire:click="deleteBackup('{{ $b['path'] }}')"
                                        wire:confirm="{{ __('Delete this backup?') }}"
                                        class="text-xs font-medium text-red-500 hover:underline">{{ __('Delete') }}</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-8 text-center text-sm text-chrome-400">{{ __('No backups yet — one is taken automatically each day, or press “Back up now”.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Restore confirmation (password-gated) --}}
    @if ($restorePath !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-chrome-900/50 p-4" wire:key="restore-modal">
            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
                <h2 class="text-lg font-semibold text-chrome-900">{{ __('Restore this backup?') }}</h2>
                <p class="mt-2 text-sm text-chrome-600">{{ __('This rewrites the entire database to :name. Everything since then is lost. Enter your password to confirm.', ['name' => basename($restorePath)]) }}</p>
                <div class="mt-4">
                    <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Your password') }}</label>
                    <input type="password" wire:model="restorePassword" wire:keydown.enter="restore" class="o-input w-full" autocomplete="current-password">
                    @error('restorePassword') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" wire:click="cancelRestore" class="rounded-lg px-4 py-2 text-sm font-medium text-chrome-600 ring-1 ring-chrome-300 hover:bg-chrome-50">{{ __('Cancel') }}</button>
                    <button type="button" wire:click="restore" wire:loading.attr="disabled" wire:target="restore"
                        class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
                        <span wire:loading.remove wire:target="restore">{{ __('Restore database') }}</span>
                        <span wire:loading wire:target="restore">{{ __('Restoring…') }}</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <div class="relative grow sm:max-w-xs">
            <svg class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-chrome-400"
                viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 3.5 9.74l3.38 3.38a1 1 0 0 0 1.42-1.42l-3.38-3.38A5.5 5.5 0 0 0 9 3.5ZM5.5 9a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0Z" clip-rule="evenodd"/>
            </svg>
            <input type="text" wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search user, detail, or IP…') }}"
                class="o-input ps-9">
        </div>
        <select wire:model.live="action" class="o-input max-w-[12rem]">
            <option value="">{{ __('All actions') }}</option>
            @foreach ($actions as $code)
                <option value="{{ $code }}">{{ __(\App\Models\ActivityLog::LABELS[$code] ?? ucfirst(str_replace('_', ' ', $code))) }}</option>
            @endforeach
        </select>
        @if ($search !== '' || $action !== '')
            <button type="button" wire:click="clearFilters"
                class="rounded-md px-3 py-2 text-sm font-medium text-chrome-600 ring-1 ring-chrome-300 hover:bg-chrome-50">
                {{ __('Clear') }}
            </button>
        @endif
    </div>

    <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="border-b border-chrome-200 bg-chrome-50 text-xs uppercase tracking-wide text-chrome-500">
                    <tr>
                        <th class="px-4 py-3 text-start font-semibold">{{ __('Date & time') }}</th>
                        <th class="px-4 py-3 text-start font-semibold">{{ __('User') }}</th>
                        <th class="px-4 py-3 text-start font-semibold">{{ __('Action') }}</th>
                        <th class="px-4 py-3 text-start font-semibold">{{ __('Details') }}</th>
                        <th class="px-4 py-3 text-start font-semibold">{{ __('IP') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-chrome-100">
                    @forelse ($logs as $log)
                        <tr wire:key="log-{{ $log->id }}" class="hover:bg-chrome-50/60">
                            <td class="whitespace-nowrap px-4 py-3 align-top">
                                <span class="block font-medium text-chrome-800">{{ $log->created_at?->format('M j, Y') }}</span>
                                <span class="block text-xs text-chrome-400">{{ $log->created_at?->format('g:i A') }} · {{ $log->created_at?->diffForHumans() }}</span>
                            </td>
                            <td class="px-4 py-3 align-top">
                                <span class="flex items-center gap-2">
                                    @if ($log->user_is_admin)
                                        <span class="shrink-0 rounded-full bg-primary-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-primary-700">{{ __('Admin') }}</span>
                                    @else
                                        <span class="shrink-0 rounded-full bg-chrome-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-chrome-500">{{ __('Staff') }}</span>
                                    @endif
                                    <span class="font-medium text-chrome-800">{{ $log->user_name }}</span>
                                </span>
                            </td>
                            <td class="px-4 py-3 align-top">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $log->actionColor() }}">
                                    {{ $log->actionLabel() }}
                                </span>
                            </td>
                            <td class="px-4 py-3 align-top text-chrome-700">
                                @if ($log->subject)
                                    <span class="block font-medium">{{ $log->subject }}</span>
                                @endif
                                @if ($log->description)
                                    <span class="block text-xs text-chrome-400">{{ $log->description }}</span>
                                @endif
                                @if (! $log->subject && ! $log->description)
                                    <span class="text-chrome-300">—</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 align-top text-xs text-chrome-400">{{ $log->ip_address ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-12 text-center text-sm text-chrome-400">
                                {{ __('No activity recorded yet.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($logs->hasPages())
        <div class="mt-4">
            {{ $logs->links('vendor.pagination.compact') }}
        </div>
    @endif
</div>
