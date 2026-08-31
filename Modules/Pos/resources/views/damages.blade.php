@php use App\Erp\Views\ValueFormat; @endphp
<div class="mx-auto max-w-6xl p-4 sm:p-6">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ __('Damage Report') }}</h1>
            <p class="text-sm text-chrome-500">{{ __('Stock written off to breakage, spoilage, spillage, expiry or theft.') }}</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ url('/app/pos/damage/export?from=' . urlencode($from) . '&to=' . urlencode($to) . '&reason=' . urlencode($reason)) }}"
               class="o-btn-ghost">{{ __('Export CSV') }}</a>
            @if ($canCreate)
                <a href="{{ url('/app/pos/damage/new') }}" wire:navigate class="o-btn-primary">
                    <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                    {{ __('Log damage') }}
                </a>
            @endif
        </div>
    </div>

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap items-end gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
        <div>
            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('From') }}</label>
            <x-date-field wire:model.live="from" class="o-input" />
        </div>
        <div>
            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('To') }}</label>
            <x-date-field wire:model.live="to" class="o-input" />
        </div>
        <div>
            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Reason') }}</label>
            <select wire:model.live="reason" class="o-input">
                <option value="">{{ __('All reasons') }}</option>
                @foreach ($reasons as $value => $label)
                    <option value="{{ $value }}">{{ __($label) }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- KPI strip --}}
    <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3">
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
            <div class="text-xs uppercase tracking-wide text-chrome-400">{{ __('Entries') }}</div>
            <div class="mt-1 text-2xl font-bold text-chrome-900">{{ $count }}</div>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
            <div class="text-xs uppercase tracking-wide text-chrome-400">{{ __('Units lost') }}</div>
            <div class="mt-1 text-2xl font-bold text-chrome-900">{{ rtrim(rtrim(number_format($totalQty, 3), '0'), '.') }}</div>
        </div>
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
            <div class="text-xs uppercase tracking-wide text-chrome-400">{{ __('Total loss') }}</div>
            <div class="mt-1 text-2xl font-bold text-red-600">{{ ValueFormat::money($totalLoss) }}</div>
        </div>
    </div>

    {{-- Entries --}}
    <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
        <table class="w-full min-w-[760px] divide-y divide-chrome-100 text-sm">
            <thead class="bg-chrome-50 text-xs uppercase tracking-wide text-chrome-400">
                <tr>
                    <th class="px-4 py-2 text-start">{{ __('Reference') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Item') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Qty') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Unit cost') }}</th>
                    <th class="px-4 py-2 text-end">{{ __('Loss') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('Reason') }}</th>
                    <th class="px-4 py-2 text-start">{{ __('By') }}</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-100">
                @forelse ($entries as $entry)
                    <tr wire:key="damage-{{ $entry->id }}">
                        <td class="px-4 py-2 font-mono text-xs text-chrome-500">{{ $entry->reference }}</td>
                        <td class="px-4 py-2 text-chrome-700">{{ $entry->damaged_on?->format('Y-m-d') }}</td>
                        <td class="px-4 py-2 text-chrome-800">
                            {{ $entry->item_label }}
                            <span class="ms-1 rounded-full bg-chrome-100 px-2 py-0.5 text-[10px] font-medium text-chrome-600">{{ __(ucfirst($entry->item_type)) }}</span>
                            @if ($entry->note)
                                <div class="text-xs text-chrome-400">{{ $entry->note }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-end text-chrome-700">{{ rtrim(rtrim(number_format($entry->quantity, 3), '0'), '.') }}</td>
                        <td class="px-4 py-2 text-end text-chrome-500">{{ ValueFormat::money($entry->unit_cost) }}</td>
                        <td class="px-4 py-2 text-end font-medium text-red-600">{{ ValueFormat::money($entry->loss_value) }}</td>
                        <td class="px-4 py-2 text-chrome-600">{{ $entry->reason_label }}</td>
                        <td class="px-4 py-2 text-chrome-500">{{ $entry->recorded_by ?? '—' }}</td>
                        <td class="px-4 py-2 text-end">
                            @if ($canDelete)
                                <button type="button"
                                    wire:click="delete({{ $entry->id }})"
                                    wire:confirm="{{ __('Remove this entry and restore the stock?') }}"
                                    class="text-xs text-red-500 hover:underline">{{ __('remove') }}</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="px-4 py-10 text-center text-sm text-chrome-400">{{ __('No damage logged in this range.') }}</td>
                    </tr>
                @endforelse
            </tbody>
            @if ($count > 0)
                <tfoot class="border-t-2 border-chrome-200 bg-chrome-50 text-sm font-semibold text-chrome-800">
                    <tr>
                        <td class="px-4 py-2" colspan="5">{{ __('Total') }}</td>
                        <td class="px-4 py-2 text-end text-red-600">{{ ValueFormat::money($totalLoss) }}</td>
                        <td class="px-4 py-2" colspan="3"></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</div>
