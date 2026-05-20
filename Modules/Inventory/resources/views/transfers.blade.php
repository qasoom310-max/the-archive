@php $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.'); @endphp

<div class="mx-auto max-w-6xl p-6">
    <div class="mb-4 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">
                {{ $activeType?->name ?? 'All Transfers' }}
            </h1>
            <p class="text-sm text-chrome-500">Stock moves — validate to post them to inventory.</p>
        </div>
        <a href="{{ url('/app/inventory/transfers/new' . ($activeType ? '?type=' . $activeType->id : '')) }}"
            wire:navigate class="o-btn-primary">New transfer</a>
    </div>

    @if ($flash !== '')
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200">
            {{ $flash }}
        </div>
    @endif

    <div class="mb-4 flex flex-wrap gap-1">
        <a href="{{ url('/app/inventory/transfers') }}" wire:navigate
            class="o-btn {{ $type === null ? 'o-btn-primary' : 'o-btn-ghost' }}">All</a>
        @foreach ($types as $t)
            <a href="{{ url('/app/inventory/transfers?type=' . $t->id) }}" wire:navigate
                class="o-btn {{ $type === $t->id ? 'o-btn-primary' : 'o-btn-ghost' }}">{{ $t->name }}</a>
        @endforeach
    </div>

    <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
        <table class="min-w-full divide-y divide-chrome-200 text-sm">
            <thead class="bg-chrome-50 text-xs uppercase tracking-wide text-chrome-500">
                <tr>
                    <th class="px-4 py-2 text-left">Reference</th>
                    <th class="px-4 py-2 text-left">Operation</th>
                    <th class="px-4 py-2 text-right">Qty</th>
                    <th class="px-4 py-2 text-left">From → To</th>
                    <th class="px-4 py-2 text-left">State</th>
                    <th class="px-4 py-2 text-left">Scheduled</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-100">
                @forelse ($moves as $m)
                    <tr wire:key="move-{{ $m->id }}" class="hover:bg-chrome-50">
                        <td class="px-4 py-2 font-medium text-chrome-800">{{ $m->reference }}</td>
                        <td class="px-4 py-2 text-chrome-500">{{ $m->operationType?->name ?? '—' }}</td>
                        <td class="px-4 py-2 text-right">{{ $num($m->product_qty) }}</td>
                        <td class="px-4 py-2 text-chrome-500">
                            {{ $m->source?->name ?? '—' }} <span class="text-chrome-300">→</span> {{ $m->destination?->name ?? '—' }}
                        </td>
                        <td class="px-4 py-2">
                            <span class="o-chip {{ $m->state->value === 'done' ? 'bg-emerald-50 text-emerald-700' : ($m->state->value === 'cancelled' ? 'bg-chrome-100 text-chrome-500' : 'bg-amber-50 text-amber-700') }}">
                                {{ $m->state->label() }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-chrome-400">{{ $m->scheduled_at?->isoFormat('MMM D, HH:mm') ?? '—' }}</td>
                        <td class="px-4 py-2 text-right">
                            @if ($m->state->isOpen())
                                <button wire:click="validateMove({{ $m->id }})"
                                    wire:confirm="Validate {{ $m->reference }}? This posts the move to inventory."
                                    class="o-btn-primary">Validate</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-sm text-chrome-400">No transfers.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
