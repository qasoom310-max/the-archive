<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <x-form-breadcrumb :parent="__('Production & store')" :parent-url="url('/app/pos/production')" :current="__('New production')" />

    @php
        $lbl = 'mb-1 block text-sm font-medium text-chrome-700';
        $unitLabel = fn (?string $u) => collect(\Modules\Pos\Models\PosProduct::UNIT_OPTIONS)->firstWhere('value', $u)['label'] ?? $u;
        $variance = max(0, $expected - (int) ($produced_units === '' ? 0 : $produced_units));
    @endphp

    {{-- Product --}}
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06] sm:p-6">
        <h2 class="mb-4 text-sm font-semibold text-chrome-800">{{ __('What are we making?') }}</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="{{ $lbl }}">{{ __('Product') }} *</label>
                <select wire:model.live="product_id" class="o-input w-full">
                    <option value="">{{ __('— Select —') }}</option>
                    @foreach ($products as $p)
                        <option value="{{ $p->id }}">{{ $p->name }}@if ($p->bottle_size_ml) · {{ rtrim(rtrim(number_format((float) $p->bottle_size_ml, 1), '0'), '.') }} ml @endif</option>
                    @endforeach
                </select>
                @error('product_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Bottle size (ml)') }}</label>
                <input type="text" value="{{ $bottleSize > 0 ? rtrim(rtrim(number_format($bottleSize, 3), '0'), '.') . ' ml' : '—' }}" disabled class="o-input w-full bg-chrome-50 text-chrome-600">
                @if ($product_id && $bottleSize <= 0)
                    <p class="mt-1 text-xs text-amber-600">{{ __('This product has no bottle size — set it on the product first.') }}</p>
                @endif
            </div>
        </div>
    </div>

    {{-- Materials mixed --}}
    <div class="mt-5 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06] sm:p-6">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-2">
            <div>
                <h2 class="text-sm font-semibold text-chrome-800">{{ __('Materials mixed') }}</h2>
                <p class="text-xs text-chrome-400">{{ __('Enter how much of each raw material you actually used (ml).') }}</p>
                @if ($hasFormula)
                    <p class="mt-1 text-xs text-emerald-600">{{ __('Auto-filled from this product’s saved formula — adjust if needed.') }}</p>
                @endif
            </div>
            <button type="button" wire:click="saveAsFormula" class="shrink-0 rounded-lg border border-chrome-200 px-2.5 py-1 text-xs font-medium text-chrome-600 hover:bg-chrome-50">
                {{ $hasFormula ? __('Update formula') : __('Save as formula') }}
            </button>
        </div>
        @if ($formulaJustSaved)
            <p class="mb-2 rounded-lg bg-emerald-50 px-3 py-1.5 text-xs font-medium text-emerald-700">{{ __('Formula saved — it will auto-fill next time.') }}</p>
        @endif
        @error('lines') <p class="mb-2 text-sm text-red-600">{{ $message }}</p> @enderror

        <div class="space-y-3">
            @foreach ($lines as $i => $line)
                <div wire:key="pline-{{ $i }}" class="grid grid-cols-1 gap-3 sm:grid-cols-[1fr,10rem,auto] sm:items-end">
                    <div>
                        <label class="{{ $lbl }} sm:sr-only">{{ __('Material') }}</label>
                        <select wire:model="lines.{{ $i }}.ingredient_id" class="o-input w-full">
                            <option value="">{{ __('— Select material —') }}</option>
                            @foreach ($ingredients as $ing)
                                <option value="{{ $ing->id }}">{{ $ing->name }} ({{ rtrim(rtrim(number_format($ing->availableMl(), 1), '0'), '.') }} {{ __('ml') }}{{ (float) $ing->pack_size > 1 ? ' · ' . rtrim(rtrim(number_format((float) $ing->stock_on_hand, 1), '0'), '.') . '×' . rtrim(rtrim(number_format((float) $ing->pack_size, 1), '0'), '.') . ' ' . $unitLabel($ing->unit) : '' }})</option>
                            @endforeach
                        </select>
                        @error('lines.'.$i.'.ingredient_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $lbl }} sm:sr-only">{{ __('ML used') }}</label>
                        <input type="number" step="0.001" min="0" wire:model.live="lines.{{ $i }}.ml_used" class="o-input w-full" placeholder="{{ __('ml') }}">
                        @error('lines.'.$i.'.ml_used') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="pb-1">
                        @if (count($lines) > 1)
                            <button type="button" wire:click="removeLine({{ $i }})" class="text-xs font-medium text-red-600 hover:text-red-700">{{ __('Remove') }}</button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <button type="button" wire:click="addLine" class="mt-3 inline-flex items-center gap-1.5 text-sm font-medium text-primary-700 hover:text-primary-800">
            <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
            {{ __('Add material') }}
        </button>
    </div>

    {{-- Yield --}}
    <div class="mt-5 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06] sm:p-6">
        <h2 class="mb-4 text-sm font-semibold text-chrome-800">{{ __('Yield') }}</h2>
        <dl class="space-y-2 text-sm">
            <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Total mix') }}</dt><dd class="font-medium text-chrome-800">{{ rtrim(rtrim(number_format($totalMix, 3), '0'), '.') }} ml</dd></div>
            <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Bottle size') }}</dt><dd class="text-chrome-600">{{ $bottleSize > 0 ? rtrim(rtrim(number_format($bottleSize, 3), '0'), '.') . ' ml' : '—' }}</dd></div>
            <div class="flex items-center justify-between rounded-lg bg-primary-50 px-3 py-2"><dt class="font-semibold text-primary-800">{{ __('Expected bottles') }}</dt><dd class="text-lg font-bold text-primary-700">{{ $expected }}</dd></div>
        </dl>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="{{ $lbl }}">{{ __('Bottles actually produced') }} *</label>
                <input type="number" min="0" wire:model.live="produced_units" class="o-input w-full">
                @error('produced_units') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $lbl }}">{{ __('Shortfall') }}</label>
                <input type="text" value="{{ $variance }}" disabled class="o-input w-full {{ $variance > 0 ? 'bg-red-50 font-semibold text-red-700' : 'bg-chrome-50 text-chrome-600' }}">
                @if ($variance > 0)<p class="mt-1 text-xs text-red-600">{{ __(':n fewer than expected — check for waste.', ['n' => $variance]) }}</p>@endif
            </div>
        </div>
        <div class="mt-4">
            <label class="{{ $lbl }}">{{ __('Notes') }}</label>
            <textarea wire:model="notes" rows="2" class="o-input w-full"></textarea>
        </div>

        <button wire:click="save" class="o-btn-primary mt-5 w-full justify-center py-2.5">
            <span wire:loading.remove wire:target="save">{{ __('Record production → store') }}</span>
            <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
        </button>
        <a href="{{ url('/app/pos/production') }}" wire:navigate class="mt-2 block text-center text-sm text-chrome-500 hover:text-chrome-700">{{ __('Cancel') }}</a>
    </div>
</div>
