@php
    $yield = $product?->theoreticalYield();
@endphp

<div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
    <div class="mb-3 flex items-center justify-between">
        <div>
            <h2 class="text-sm font-semibold text-chrome-800">Recipe (static ingredient consumption)</h2>
            <p class="text-xs text-chrome-500">Each sale of one unit decrements these components from stock.</p>
        </div>
        @if ($yield !== null)
            <span class="o-chip {{ $yield <= 0 ? 'bg-red-50 text-red-600' : 'bg-emerald-50 text-emerald-700' }}">
                {{ $yield }} available serving{{ $yield === 1 ? '' : 's' }}
            </span>
        @endif
    </div>

    @if ($lines->isEmpty())
        <p class="rounded-lg border border-dashed border-chrome-300 p-4 text-center text-xs text-chrome-400">
            No recipe — this product does not consume any components.
        </p>
    @else
        <table class="min-w-full divide-y divide-chrome-100 text-sm">
            <thead class="text-xs uppercase tracking-wide text-chrome-400">
                <tr>
                    <th class="py-1 text-left">Component</th>
                    <th class="py-1 text-right">Qty / unit</th>
                    <th class="py-1 text-right">Component stock</th>
                    <th class="py-1"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-100">
                @foreach ($lines as $line)
                    <tr wire:key="recipe-{{ $line->id }}">
                        <td class="py-1.5 text-chrome-800">{{ $line->component?->name ?? '—' }}</td>
                        <td class="py-1.5 text-right text-chrome-600">{{ rtrim(rtrim(number_format($line->quantity_consumed, 3), '0'), '.') }}</td>
                        <td class="py-1.5 text-right text-chrome-500">
                            {{ rtrim(rtrim(number_format($line->component?->stock_on_hand ?? 0, 3), '0'), '.') }}
                        </td>
                        <td class="py-1.5 text-right">
                            <button wire:click="removeLine({{ $line->id }})"
                                class="text-xs text-red-500 hover:underline">remove</button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="mt-4 flex flex-wrap items-end gap-2 border-t border-chrome-100 pt-4">
        <div class="min-w-48 flex-1">
            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">Component</label>
            <select wire:model="componentId" class="o-input">
                <option value="">Select a product…</option>
                @foreach ($components as $c)
                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="w-32">
            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">Qty / unit</label>
            <input type="number" step="0.001" min="0" wire:model="quantity" class="o-input">
        </div>
        <button wire:click="addLine" class="o-btn-primary">Add component</button>
    </div>
</div>
