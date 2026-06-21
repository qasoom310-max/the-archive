<div class="mx-auto max-w-6xl p-4 sm:p-6">
    <div class="mb-5">
        <h1 class="text-xl font-bold text-chrome-900">{{ __('Activity log') }}</h1>
        <p class="text-sm text-chrome-500">{{ __('Everything admins and members do, with date and time.') }}</p>
    </div>

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
