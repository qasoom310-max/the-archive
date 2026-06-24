<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <div class="mb-4 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ __('Car replacements') }}</h1>
            <p class="text-sm text-chrome-500">{{ __('Cars swapped out for customers.') }}</p>
        </div>
        <a href="{{ url('/app/rental/replacement/new') }}" wire:navigate class="o-btn-primary">
            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
            {{ __('New replacement') }}
        </a>
    </div>

    @php $tabs = ['all' => __('All'), 'active' => __('Active'), 'closed' => __('Closed')]; @endphp
    <div class="mb-4 flex flex-wrap items-center gap-1 border-b border-chrome-200">
        @foreach ($tabs as $key => $label)
            @php $n = $key === 'all' ? $totalCount : (int) $counts->get($key, 0); @endphp
            <button wire:click="$set('tab', '{{ $key }}')"
                class="-mb-px flex items-center gap-1.5 border-b-2 px-4 py-2 text-sm font-medium {{ $tab === $key ? 'border-primary-600 text-primary-700' : 'border-transparent text-chrome-500 hover:text-chrome-800' }}">
                {{ $label }}
                <span class="rounded-full bg-chrome-100 px-1.5 text-[11px] text-chrome-500">{{ $n }}</span>
            </button>
        @endforeach
    </div>

    <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
        <table class="min-w-full divide-y divide-chrome-100 text-sm">
            <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                <tr>
                    <th class="px-4 py-2 text-start">{{ __('Reference') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Customer') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Original car') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Replacement car') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-50">
                @forelse ($replacements as $r)
                    <tr wire:key="rep-{{ $r->id }}" class="cursor-pointer hover:bg-chrome-50"
                        onclick="window.location='{{ url('/app/rental/replacement/' . $r->id) }}'">
                        <td class="px-4 py-2 font-medium text-chrome-800">{{ $r->reference }}</td>
                        <td class="px-4 py-2 text-chrome-700">{{ $r->customer?->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-chrome-700">{{ $r->originalVehicle?->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-chrome-700">{{ $r->replacementVehicle?->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-chrome-600">{{ $r->date?->isoFormat('MMM D, YYYY') ?? '—' }}</td>
                        <td class="px-4 py-2"><span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $r->status === 'active' ? 'bg-sky-100 text-sky-700' : 'bg-emerald-100 text-emerald-700' }}">{{ __(ucfirst($r->status)) }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No replacements found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $replacements->links() }}</div>
</div>
