<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <x-form-breadcrumb :parent="__('Production & store')" :parent-url="url('/app/pos/production')" :current="$isEditing ? ($reference ?: __('Production')) : __('New production')" />

    @php
        $lbl = 'mb-1 block text-sm font-medium text-chrome-700';
        $unitLabel = fn (?string $u) => collect(\Modules\Pos\Models\PosProduct::UNIT_OPTIONS)->firstWhere('value', $u)['label'] ?? $u;
        $variance = max(0, $expected - (int) ($produced_units === '' ? 0 : $produced_units));
        $money = fn ($v) => \App\Erp\Views\ValueFormat::money((float) $v);
    @endphp

    {{-- Lifecycle banner --}}
    @if ($isEditing && $state === 'reversed')
        <div class="mb-4 flex items-center gap-2 rounded-lg bg-red-50 px-4 py-2.5 text-sm font-medium text-red-700 ring-1 ring-red-100">
            <svg class="size-4 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm-1.5-5.5 5-5-1-1-5 5 1 1Z" clip-rule="evenodd"/></svg>
            {{ __('This production is reversed — kept for the record. Reopen it to edit and re-record.') }}
        </div>
    @elseif ($isEditing && $state === 'draft')
        <div class="mb-4 flex items-center gap-2 rounded-lg bg-amber-50 px-4 py-2.5 text-sm font-medium text-amber-700 ring-1 ring-amber-100">
            <svg class="size-4 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path d="M13.586 3.586a2 2 0 1 1 2.828 2.828l-8.5 8.5A1 1 0 0 1 7.5 15H5a1 1 0 0 1-1-1v-2.5a1 1 0 0 1 .293-.707l8.5-8.5Z"/></svg>
            {{ __('Reopened for editing — the stock was returned. Re-record to apply it again.') }}
        </div>
    @elseif ($isEditing && $state === 'done')
        <div class="mb-4 flex items-center gap-2 rounded-lg bg-chrome-50 px-4 py-2.5 text-sm text-chrome-600 ring-1 ring-chrome-100">
            <svg class="size-4 shrink-0 text-chrome-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-8-4a1 1 0 0 0-1 1v3a1 1 0 0 0 .293.707l2 2 1.414-1.414L11 9.586V7a1 1 0 0 0-1-1Z" clip-rule="evenodd"/></svg>
            {{ __('This production is recorded. To correct it, Reverse it or Reopen it to edit — don’t edit the stock directly.') }}
        </div>
    @endif
    @error('state') <p class="mb-4 rounded-lg bg-red-50 px-4 py-2.5 text-sm font-medium text-red-700">{{ $message }}</p> @enderror

    {{-- Product --}}
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06] sm:p-6">
        <h2 class="mb-4 text-sm font-semibold text-chrome-800">{{ __('What are we making?') }}</h2>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="{{ $lbl }}">{{ __('Product') }} *</label>
                <select wire:model.live="product_id" @disabled(! $editable) class="o-input w-full disabled:bg-chrome-50 disabled:text-chrome-500">
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
            @if ($editable)
                <button type="button" wire:click="saveAsFormula" class="shrink-0 rounded-lg border border-chrome-200 px-2.5 py-1 text-xs font-medium text-chrome-600 hover:bg-chrome-50">
                    {{ $hasFormula ? __('Update formula') : __('Save as formula') }}
                </button>
            @endif
        </div>
        @if ($formulaJustSaved)
            <p class="mb-2 rounded-lg bg-emerald-50 px-3 py-1.5 text-xs font-medium text-emerald-700">{{ __('Formula saved — it will auto-fill next time.') }}</p>
        @endif
        @error('lines') <p class="mb-2 text-sm text-red-600">{{ $message }}</p> @enderror

        @php
            $numf = fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.');
            // Group the picker options by managed category (Oils, Bottles, Caps…).
            $grouped = $ingredients->groupBy(fn ($i) => $i->category_name ?: __('Uncategorised'));
        @endphp
        <div class="space-y-3">
            @foreach ($lines as $i => $line)
                @php
                    $sel = $ingredients->firstWhere('id', (int) ($line['ingredient_id'] ?? 0));
                    $avail = $sel?->availableMl();
                    $used = (float) (($line['ml_used'] ?? '') === '' ? 0 : $line['ml_used']);
                    $left = $avail !== null ? $avail - $used : null;
                @endphp
                <div wire:key="pline-{{ $i }}" class="grid grid-cols-1 gap-3 sm:grid-cols-[1fr,10rem,auto] sm:items-start">
                    <div>
                        <label class="{{ $lbl }} sm:sr-only">{{ __('Material') }}</label>
                        <select wire:model.live="lines.{{ $i }}.ingredient_id" @disabled(! $editable) class="o-input w-full disabled:bg-chrome-50 disabled:text-chrome-500">
                            <option value="">{{ __('— Select material —') }}</option>
                            @foreach ($grouped as $groupName => $groupIngs)
                                <optgroup label="{{ $groupName }}">
                                    @foreach ($groupIngs as $ing)
                                        <option value="{{ $ing->id }}" @disabled($ing->availableMl() <= 0)>{{ $ing->name }} — {{ $ing->availableMl() <= 0 ? __('out of stock') : $numf($ing->availableMl()) . ' ' . __('ml') }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        @error('lines.'.$i.'.ingredient_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $lbl }} sm:sr-only">{{ __('ML used') }}</label>
                        <input type="number" step="0.001" min="0" wire:model.live="lines.{{ $i }}.ml_used" @disabled(! $editable) class="o-input w-full disabled:bg-chrome-50 disabled:text-chrome-500" placeholder="{{ __('ml') }}">
                        @if ($sel)
                            <p class="mt-1 text-xs {{ $left < 0 ? 'font-medium text-red-600' : 'text-chrome-400' }}">{{ __('In stock') }}: {{ $numf($avail) }} · {{ __('Left') }}: {{ $numf($left) }} ml</p>
                            <p class="mt-0.5 text-xs text-chrome-500">{{ __('Cost') }}: {{ $money($used * $sel->costPerMl()) }}</p>
                        @endif
                        @error('lines.'.$i.'.ml_used') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="pt-2">
                        @if ($editable && count($lines) > 1)
                            <button type="button" wire:click="removeLine({{ $i }})" class="text-xs font-medium text-red-600 hover:text-red-700">{{ __('Remove') }}</button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        @if ($editable)
            <button type="button" wire:click="addLine" class="mt-3 inline-flex items-center gap-1.5 text-sm font-medium text-primary-700 hover:text-primary-800">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                {{ __('Add material') }}
            </button>
        @endif
    </div>

    {{-- Packaging (per bottle) --}}
    <div class="mt-5 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06] sm:p-6">
        <div class="mb-1">
            <h2 class="text-sm font-semibold text-chrome-800">{{ __('Packaging (per bottle)') }}</h2>
            <p class="text-xs text-chrome-400">{{ __('Bottle, cap, pump, box, sticker… consumed for each finished bottle. Total used = per bottle × bottles produced.') }}</p>
        </div>
        @php $prodCount = (int) ($produced_units === '' ? 0 : $produced_units); @endphp

        @if (count($packaging) > 0)
            <div class="mt-3 space-y-3">
                @foreach ($packaging as $i => $line)
                    @php
                        $psel = $ingredients->firstWhere('id', (int) ($line['ingredient_id'] ?? 0));
                        $per = (float) (($line['qty'] ?? '') === '' ? 0 : $line['qty']);
                        $need = $per * $prodCount;
                        $pstock = $psel !== null ? (float) $psel->stock_on_hand : null;
                        $pleft = $pstock !== null ? $pstock - $need : null;
                    @endphp
                    <div wire:key="ppack-{{ $i }}" class="grid grid-cols-1 gap-3 sm:grid-cols-[1fr,8rem,auto] sm:items-start">
                        <div>
                            <label class="{{ $lbl }} sm:sr-only">{{ __('Packaging') }}</label>
                            <select wire:model.live="packaging.{{ $i }}.ingredient_id" @disabled(! $editable) class="o-input w-full disabled:bg-chrome-50 disabled:text-chrome-500">
                                <option value="">{{ __('— Select packaging —') }}</option>
                                @foreach ($grouped as $groupName => $groupIngs)
                                    <optgroup label="{{ $groupName }}">
                                        @foreach ($groupIngs as $ing)
                                            <option value="{{ $ing->id }}" @disabled((float) $ing->stock_on_hand <= 0)>{{ $ing->name }} — {{ (float) $ing->stock_on_hand <= 0 ? __('out of stock') : $numf($ing->stock_on_hand) . ' ' . $ing->unit }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                            @error('packaging.'.$i.'.ingredient_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="{{ $lbl }} sm:sr-only">{{ __('Per bottle') }}</label>
                            <input type="number" step="0.001" min="0" wire:model.live="packaging.{{ $i }}.qty" @disabled(! $editable) class="o-input w-full disabled:bg-chrome-50 disabled:text-chrome-500" placeholder="{{ __('per bottle') }}">
                            @if ($psel)
                                <p class="mt-1 text-xs {{ $pleft < 0 ? 'font-medium text-red-600' : 'text-chrome-400' }}">{{ __('Need') }}: {{ $numf($need) }} · {{ __('In stock') }}: {{ $numf($pstock) }}</p>
                                <p class="mt-0.5 text-xs text-chrome-500">{{ __('Cost') }}: {{ $money($need * (float) $psel->cost_price) }}</p>
                            @endif
                            @error('packaging.'.$i.'.qty') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div class="pt-2">
                            @if ($editable)
                                <button type="button" wire:click="removePackaging({{ $i }})" class="text-xs font-medium text-red-600 hover:text-red-700">{{ __('Remove') }}</button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <p class="mt-3 text-xs text-chrome-400">{{ __('No packaging yet — add the bottle, cap, and anything else each bottle uses.') }}</p>
        @endif

        @if ($editable)
            <button type="button" wire:click="addPackaging" class="mt-3 inline-flex items-center gap-1.5 text-sm font-medium text-primary-700 hover:text-primary-800">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                {{ __('Add packaging') }}
            </button>
        @endif
    </div>

    {{-- Yield --}}
    <div class="mt-5 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06] sm:p-6">
        <h2 class="mb-4 text-sm font-semibold text-chrome-800">{{ __('Yield') }}</h2>
        <dl class="space-y-2 text-sm">
            <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Total mix') }}</dt><dd class="font-medium text-chrome-800">{{ rtrim(rtrim(number_format($totalMix, 3), '0'), '.') }} ml</dd></div>
            <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Bottle size') }}</dt><dd class="text-chrome-600">{{ $bottleSize > 0 ? rtrim(rtrim(number_format($bottleSize, 3), '0'), '.') . ' ml' : '—' }}</dd></div>
            <div class="flex items-center justify-between rounded-lg bg-primary-50 px-3 py-2"><dt class="font-semibold text-primary-800">{{ __('Expected bottles') }}</dt><dd class="text-lg font-bold text-primary-700">{{ $expected }}</dd></div>
        </dl>

        {{-- Cost breakdown --}}
        <dl class="mt-3 space-y-2 border-t border-chrome-100 pt-3 text-sm">
            <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Materials cost') }}</dt><dd class="font-medium text-chrome-800">{{ $money($materialsCost) }}</dd></div>
            <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Packaging cost') }}</dt><dd class="text-chrome-600">{{ $money($packagingCost) }}</dd></div>
            <div class="flex items-center justify-between rounded-lg bg-chrome-50 px-3 py-2"><dt class="font-semibold text-chrome-700">{{ __('Total cost') }}</dt><dd class="text-base font-bold text-chrome-900">{{ $money($totalCost) }}</dd></div>
            <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Cost per bottle') }}</dt><dd class="font-medium text-chrome-800">{{ $unitCost > 0 ? $money($unitCost) : '—' }}</dd></div>
        </dl>

        <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="{{ $lbl }}">{{ __('Bottles actually produced') }} *</label>
                <input type="number" min="0" wire:model.live="produced_units" @disabled(! $editable) class="o-input w-full disabled:bg-chrome-50 disabled:text-chrome-500">
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
            <textarea wire:model="notes" rows="2" @disabled(! $editable) class="o-input w-full disabled:bg-chrome-50 disabled:text-chrome-500"></textarea>
        </div>

        @if ($editable)
            {{-- New run, or a reopened draft being re-recorded. --}}
            <button wire:click="save" class="o-btn-primary mt-5 w-full justify-center py-2.5">
                <span wire:loading.remove wire:target="save">{{ $isEditing ? __('Re-record production → store') : __('Record production → store') }}</span>
                <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
            </button>
            <a href="{{ url('/app/pos/production') }}" wire:navigate class="mt-2 block text-center text-sm text-chrome-500 hover:text-chrome-700">{{ __('Cancel') }}</a>
        @else
            {{-- Recorded (done) or reversed run: correct it through Reverse / Reopen, never by editing stock directly. --}}
            <div class="mt-5 space-y-2">
                @if ($state === 'done')
                    <button type="button" wire:click="reverse" wire:confirm="{{ __('Reverse this production? Materials go back to stock and the bottles leave the store. It stays on record as reversed.') }}"
                        class="w-full justify-center rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-sm font-semibold text-red-700 hover:bg-red-100">
                        {{ __('Reverse production') }}
                    </button>
                @endif
                <button type="button" wire:click="reopen" wire:confirm="{{ __('Reopen this production for editing? Its stock effect is undone until you re-record it.') }}"
                    class="o-btn-primary w-full justify-center py-2.5">
                    {{ __('Reopen to edit') }}
                </button>
            </div>
            <a href="{{ url('/app/pos/production') }}" wire:navigate class="mt-2 block text-center text-sm text-chrome-500 hover:text-chrome-700">{{ __('Back') }}</a>
        @endif
        @if ($isEditing)
            <button type="button" wire:click="delete" wire:confirm="{{ __('Delete this production permanently? This cannot be undone.') }}"
                class="mt-3 block w-full text-center text-sm font-medium text-red-600 hover:underline">{{ __('Delete production') }}</button>
        @endif
    </div>
</div>
