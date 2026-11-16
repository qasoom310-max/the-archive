{{--
    The twelve month cells and the Year / Target / Reached columns of one row
    of the Car income schedule. Shared by a car's row and a category's row so
    the two can never print a figure differently.

    $row: months, rent, limo, total, target, percent, remaining.
--}}
@foreach ($row['months'] as $m => $cell)
    <td class="px-2 py-2.5 text-end tabular-nums {{ $m === $currentMonth ? 'bg-primary-50/60' : '' }}">
        <div class="{{ $pick($cell) > 0 ? ($strong ? 'font-bold text-chrome-900' : 'font-medium text-chrome-800') : 'text-chrome-300' }}">{{ $plain($pick($cell)) }}</div>
        @if ($both && $cell['rent'] > 0 && $cell['limo'] > 0)
            <div class="text-[10px] leading-tight text-sky-700">{{ $plain($cell['rent']) }}</div>
            <div class="text-[10px] leading-tight text-violet-700">{{ $plain($cell['limo']) }}</div>
        @elseif ($both && $cell['total'] > 0)
            <div class="text-[10px] leading-tight {{ $cell['rent'] > 0 ? 'text-sky-700' : 'text-violet-700' }}">{{ $cell['rent'] > 0 ? __('rent') : __('limousine') }}</div>
        @endif
    </td>
@endforeach
<td class="border-l border-chrome-200 px-2 py-2.5 text-end tabular-nums">
    <div class="font-bold text-chrome-900">{{ $plain($mode === 'rent' ? $row['rent'] : ($mode === 'limo' ? $row['limo'] : $row['total'])) }}</div>
    @if ($both && $row['rent'] > 0)
        <div class="text-[10px] leading-tight text-sky-700">{{ $plain($row['rent']) }}</div>
    @endif
    @if ($both && $row['limo'] > 0)
        <div class="text-[10px] leading-tight text-violet-700">{{ $plain($row['limo']) }}</div>
    @endif
</td>
<td class="px-2 py-2.5 text-end tabular-nums text-chrome-600">{{ $row['target'] > 0 ? $plain($row['target']) : '—' }}</td>
<td class="px-5 py-2.5">
    @if ($row['percent'] === null)
        <span class="text-xs text-chrome-400">{{ __('No target') }}</span>
    @else
        <div class="flex items-center gap-2">
            <div class="h-1.5 w-24 overflow-hidden rounded-full bg-chrome-100">
                <div class="h-full rounded-full {{ $bar($row['percent']) }}" style="width: {{ min(100, $row['percent']) }}%"></div>
            </div>
            <span class="text-xs font-bold tabular-nums {{ $row['percent'] >= 100 ? 'text-emerald-700' : 'text-chrome-700' }}">{{ $row['percent'] }}%</span>
        </div>
        <div class="mt-0.5 text-[10px] text-chrome-400">
            {{ $row['remaining'] > 0 ? __(':left to go', ['left' => $plain((float) $row['remaining'])]) : __('Target reached') }}
        </div>
    @endif
</td>
