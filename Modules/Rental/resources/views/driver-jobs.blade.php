<div class="mt-5 overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-chrome-100 px-5 py-3">
        <h2 class="text-sm font-semibold text-chrome-800">{{ __('Job history') }}</h2>
        <span class="text-xs text-chrome-500">
            {{ trans_choice('{0}No jobs yet|{1}:count job|[2,*]:count jobs', $total, ['count' => $total]) }}
        </span>
    </div>

    <div class="flex flex-wrap items-end gap-3 border-b border-chrome-100 px-5 py-3">
        <div class="min-w-[14rem] flex-1">
            <div class="relative">
                <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-chrome-400">
                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 3.4 9.82l3.14 3.14a.75.75 0 1 0 1.06-1.06l-3.14-3.14A5.5 5.5 0 0 0 9 3.5ZM5 9a4 4 0 1 1 8 0 4 4 0 0 1-8 0Z" clip-rule="evenodd"/></svg>
                </span>
                <input type="search" wire:model.live.debounce.300ms="search"
                       class="o-input w-full ps-9 text-sm"
                       placeholder="{{ __('Reference, car, who gave it, status…') }}">
            </div>
        </div>

        {{-- Ten is enough to glance at; five hundred is for the day somebody is
             going through a driver properly. Both are one press. --}}
        <div class="flex items-center gap-1">
            <span class="text-xs text-chrome-500">{{ __('Show') }}</span>
            @foreach ($perPageOptions as $size)
                <button type="button" wire:click="setPerPage({{ $size }})"
                        class="rounded-lg border px-2.5 py-1 text-xs transition {{ $perPage === $size ? 'border-primary-500 bg-primary-50 font-medium text-primary-700' : 'border-chrome-200 text-chrome-600 hover:bg-chrome-50' }}">
                    {{ $size }}
                </button>
            @endforeach
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full divide-y divide-chrome-100 text-sm">
            <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                <tr>
                    <th class="px-4 py-2 text-start">{{ __('Type') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Reference') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Date') }}</th>
                    <th class="hidden px-4 py-2 text-start sm:table-cell">{{ __('Time') }}</th>
                    <th class="hidden px-4 py-2 text-start md:table-cell">{{ __('Given by') }}</th>
                    <th class="hidden px-4 py-2 text-start lg:table-cell">{{ __('Car used') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-50">
                @forelse ($jobs as $job)
                    <tr class="hover:bg-chrome-50">
                        <td class="px-4 py-2">
                            <span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $job['kind'] === 'rental' ? 'bg-sky-100 text-sky-700' : 'bg-violet-100 text-violet-700' }}">
                                {{ $job['type'] }}
                            </span>
                        </td>
                        <td class="px-4 py-2 font-medium">
                            <a href="{{ url($job['url']) }}" wire:navigate class="text-primary-700 hover:underline">
                                {{ $job['reference'] ?: '—' }}
                            </a>
                        </td>
                        <td class="px-4 py-2 text-chrome-600">{{ $job['at']?->isoFormat('DD-MMM-YYYY') ?? '—' }}</td>
                        <td class="hidden px-4 py-2 text-chrome-600 sm:table-cell">{{ $job['at']?->isoFormat('HH:mm') ?? '—' }}</td>
                        <td class="hidden px-4 py-2 text-chrome-600 md:table-cell">{{ $job['given_by'] ?: '—' }}</td>
                        <td class="hidden px-4 py-2 text-chrome-600 lg:table-cell">{{ $job['car'] ?: '—' }}</td>
                        <td class="px-4 py-2 text-chrome-500">{{ $job['status'] !== '' ? __(ucfirst($job['status'])) : '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-sm text-chrome-400">
                            @if ($search !== '')
                                {{ __('No job matches that.') }}
                            @else
                                {{ __('Nothing yet — trips and rentals given to this driver will appear here.') }}
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($jobs->hasPages())
        <div class="border-t border-chrome-100 px-5 py-3">{{ $jobs->links('vendor.pagination.compact') }}</div>
    @endif
</div>
