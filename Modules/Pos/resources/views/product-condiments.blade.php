<div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
    <div class="mb-3">
        <h2 class="text-sm font-semibold text-chrome-800">{{ __('Add-ons / Condiments') }}</h2>
        <p class="text-xs text-chrome-500">{{ __('Tick the add-ons the register should offer for this product. Only the ticked ones appear in the register.') }}</p>
    </div>

    @if ($condiments->isEmpty())
        <p class="rounded-lg border border-dashed border-chrome-300 p-4 text-center text-xs text-chrome-400">
            {{ __('No condiments yet. Create them under POS → POS Condiments.') }}
        </p>
    @else
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
            @foreach ($condiments as $c)
                @php $on = in_array($c->id, $assignedIds, true); @endphp
                <button type="button" wire:key="cond-{{ $c->id }}"
                    @if ($canManage) wire:click="toggle({{ $c->id }})" @else disabled @endif
                    @class([
                        'flex items-center justify-between gap-3 rounded-lg border px-3 py-2 text-start text-sm transition',
                        'border-primary-400 bg-primary-50' => $on,
                        'border-chrome-200 hover:bg-chrome-50' => ! $on,
                        'cursor-default opacity-70' => ! $canManage,
                    ])>
                    <span class="flex items-center gap-2">
                        <span @class([
                            'flex size-5 shrink-0 items-center justify-center rounded border',
                            'border-primary-500 bg-primary-500 text-chrome-900' => $on,
                            'border-chrome-300' => ! $on,
                        ])>
                            @if ($on)
                                <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0L3.3 9.7a1 1 0 0 1 1.4-1.4l3.1 3.1 6.8-6.8a1 1 0 0 1 1.4 0Z" clip-rule="evenodd" /></svg>
                            @endif
                        </span>
                        <span class="font-medium text-chrome-800">{{ $c->name }}</span>
                    </span>
                    <span class="shrink-0 text-xs text-chrome-500">
                        {{ $c->price > 0 ? \App\Erp\Money\Currencies::format($c->price) : __('Free') }}
                    </span>
                </button>
            @endforeach
        </div>
    @endif
</div>
