<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <x-page-header :title="__('Maintenance')" :subtitle="__('Service & repair history.')" icon="wrench" accent="primary">
        <x-slot:actions>
            <span class="inline-flex items-center gap-1.5 rounded-lg bg-amber-50 px-3 py-1.5 text-sm font-medium text-amber-700 ring-1 ring-amber-100">
                {{ __('Total spend') }}: <span class="font-bold">{{ \App\Erp\Views\ValueFormat::money($spendTotal) }}</span>
            </span>
            <a href="{{ url('/app/rental/maintenance/new') }}" wire:navigate class="o-btn-primary">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                {{ __('New record') }}
            </a>
        </x-slot:actions>
    </x-page-header>

    @php $tabs = ['all' => __('All'), 'pending' => __('Pending'), 'approved' => __('Approved'), 'in_progress' => __('In progress'), 'done' => __('Done')]; @endphp
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

    <div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
        <table class="w-full min-w-[720px] divide-y divide-chrome-100 text-sm">
            <thead class="bg-chrome-50 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                <tr>
                    <th class="px-4 py-2 text-start">{{ __('Reference') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Car') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Type') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Priority') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Cost') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-50">
                @forelse ($records as $m)
                    @php
                        $sb = [
                            'pending' => 'bg-amber-100 text-amber-700',
                            'approved' => 'bg-sky-100 text-sky-700',
                            'in_progress' => 'bg-indigo-100 text-indigo-700',
                            'done' => 'bg-emerald-100 text-emerald-700',
                            'declined' => 'bg-red-100 text-red-700',
                            'cancelled' => 'bg-chrome-200 text-chrome-700',
                        ][$m->status] ?? 'bg-amber-100 text-amber-700';
                        $pb = ['low' => 'text-chrome-400', 'normal' => 'text-chrome-500', 'high' => 'text-amber-600', 'critical' => 'text-red-600'][$m->priority] ?? 'text-chrome-500';
                    @endphp
                    <tr wire:key="mnt-{{ $m->id }}" class="cursor-pointer hover:bg-chrome-50"
                        onclick="window.location='{{ url('/app/rental/maintenance/' . $m->id) }}'">
                        <td class="px-4 py-2 font-medium text-chrome-800">{{ $m->reference }}</td>
                        <td class="px-4 py-2 text-chrome-700">{{ $m->vehicle?->displayName() ?? '—' }}</td>
                        <td class="px-4 py-2 text-chrome-600">{{ __(ucfirst(str_replace('_', ' ', $m->type))) }}</td>
                        <td class="px-4 py-2 text-xs font-semibold uppercase {{ $pb }}">{{ __(ucfirst($m->priority)) }}</td>
                        <td class="px-4 py-2 text-chrome-600">{{ $m->date?->isoFormat('DD-MMM-YYYY') ?? '—' }}</td>
                        <td class="px-4 py-2 text-end font-medium text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($m->cost) }}</td>
                        <td class="px-4 py-2"><span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase {{ $sb }}">{{ __($m->statusLabel()) }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No maintenance records found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $records->links() }}</div>
</div>
