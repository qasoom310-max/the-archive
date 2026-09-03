<div class="mx-auto max-w-5xl p-4 sm:p-6">
    <x-form-breadcrumb :parent="__('Petty Cash')" :parent-url="url('/app/limousine/petty_cash')" :current="$advance->reference ?? __('Advance')" />

    @php
        $sb = ['issued' => 'bg-amber-100 text-amber-700', 'confirmed' => 'bg-sky-100 text-sky-700', 'cleared' => 'bg-emerald-100 text-emerald-700'][$advance->status] ?? 'bg-chrome-200 text-chrome-700';
    @endphp

    <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/[0.06]">
        <div class="flex flex-wrap items-center gap-3">
            <span class="text-sm font-semibold text-chrome-800">{{ $advance->reference }}</span>
            <span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $sb }}">{{ __(ucfirst($advance->status)) }}</span>
            <span class="text-sm text-chrome-600">{{ $advance->driver?->name ?? '—' }}</span>
            <span class="text-sm text-chrome-400">{{ $advance->date?->isoFormat('DD-MMM-YYYY') }}</span>
            @if ($advance->issued_by)
                <span class="text-xs text-chrome-400">{{ __('Issued by :name', ['name' => $advance->issued_by]) }}</span>
            @endif
            @if ($advance->confirmed_at)
                <span class="text-xs text-chrome-400">{{ __('Confirmed by :name', ['name' => $advance->confirmed_by]) }}</span>
            @endif
        </div>
        <div class="flex items-center gap-2">
            @if ($advance->status === 'issued' && $canConfirm)
                {{-- The accountant vouches the hand-over happened before any
                     receipts are worth entering against it. --}}
                <button type="button" wire:click="confirm" class="o-btn-primary text-sm">{{ __('Confirm hand-over') }}</button>
            @elseif ($advance->status === 'issued')
                <span class="text-xs text-chrome-400">{{ __('Awaiting the accountant') }}</span>
            @endif
            @if ($advance->status === 'confirmed' && $canConfirm)
                <button type="button" wire:click="openSettle" class="o-btn-primary text-sm">{{ __('Settle') }}</button>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
                <h2 class="mb-1 text-sm font-semibold text-chrome-800">{{ __('Receipts from the driver') }}</h2>
                <p class="mb-4 text-xs text-chrome-500">{{ __('One line per paper receipt. Five slips that add up beat one figure nobody can check.') }}</p>

                @if ($advance->lines->isEmpty())
                    <p class="rounded-xl bg-chrome-50 px-3 py-4 text-sm text-chrome-500">{{ __('No receipts entered yet.') }}</p>
                @else
                    <table class="w-full text-sm">
                        <thead class="text-xs font-semibold uppercase tracking-wide text-chrome-500">
                            <tr>
                                <th class="py-1 text-start">{{ __('Date') }}</th>
                                <th class="py-1 text-start">{{ __('Category') }}</th>
                                <th class="py-1 text-start">{{ __('Description') }}</th>
                                <th class="py-1 text-end">{{ __('Amount') }}</th>
                                <th class="py-1"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-chrome-50">
                            @foreach ($advance->lines as $line)
                                <tr wire:key="pline-{{ $line->id }}">
                                    <td class="py-2 text-chrome-600">{{ $line->date?->isoFormat('DD-MMM') }}</td>
                                    <td class="py-2 text-chrome-700">{{ $line->category }}</td>
                                    <td class="py-2 text-chrome-700">
                                        {{ $line->description ?? '—' }}
                                        @if ($line->vehicle)
                                            <span class="ms-1 rounded bg-chrome-100 px-1.5 py-0.5 text-[11px] text-chrome-600">{{ $line->vehicle }}</span>
                                        @endif
                                        @if ($line->photo_path)
                                            <a href="{{ Storage::disk('public')->url($line->photo_path) }}" target="_blank" class="ms-1 text-xs text-primary-700 hover:underline">{{ __('Photo') }}</a>
                                        @endif
                                    </td>
                                    <td class="py-2 text-end font-medium text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($line->amount) }}</td>
                                    <td class="py-2 text-end">
                                        @if ($canEdit)
                                            <button type="button" wire:click="removeLine({{ $line->id }})" class="text-xs text-red-500 hover:underline">{{ __('Remove') }}</button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                @if ($canEdit)
                    <div class="mt-4 grid grid-cols-1 gap-3 rounded-xl bg-chrome-50 p-3 sm:grid-cols-3 lg:grid-cols-6"
                         x-data="{ uploading: false, uploadError: '' }">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-chrome-500">{{ __('Date') }}</label>
                            <x-date-field wire:model="lineDate" class="o-input w-full text-sm" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-chrome-500">{{ __('Category') }}</label>
                            <div class="flex items-center gap-1">
                                <select wire:model="lineCategory" class="o-input w-full text-sm">
                                    @foreach ($categories as $name)
                                        <option value="{{ $name }}">{{ $name }}</option>
                                    @endforeach
                                </select>
                                {{-- The list is the owner's: grow it from here
                                     rather than asking for a change. --}}
                                @if ($canAddCategory)
                                    <button type="button" wire:click="openCategory" title="{{ __('New category') }}" aria-label="{{ __('New category') }}"
                                            class="shrink-0 rounded-lg border border-chrome-200 px-2 py-1.5 text-sm text-chrome-600 hover:bg-chrome-50">＋</button>
                                @endif
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-chrome-500">{{ __('Car (optional)') }}</label>
                            <select wire:model="lineCarId" class="o-input w-full text-sm">
                                <option value="">—</option>
                                @foreach ($cars as $car)
                                    <option value="{{ $car->id }}">{{ $car->name }}{{ $car->plate_no ? ' · ' . $car->plate_no : '' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-chrome-500">{{ __('Description') }}</label>
                            <input type="text" wire:model="lineDescription" class="o-input w-full text-sm" placeholder="{{ __('e.g. petrol, airport parking') }}">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-chrome-500">{{ __('Amount') }}</label>
                            <input type="number" step="0.001" min="0" wire:model="lineAmount" class="o-input w-full text-sm">
                        </div>
                        <div class="flex items-end gap-2">
                            {{-- The paper itself, photographed — what settles an
                                 argument months later. --}}
                            <label class="o-btn-ghost cursor-pointer text-xs" :class="uploading && 'opacity-50'">
                                <span x-show="! uploading">{{ $linePhoto === '' ? __('Photo') : __('Photo ✓') }}</span>
                                <span x-show="uploading" x-cloak>…</span>
                                <input type="file" accept="image/*" class="hidden"
                                       x-on:change="
                                            const f = $event.target.files[0];
                                            if (!f) return;
                                            uploading = true; uploadError = '';
                                            const data = new FormData();
                                            data.append('file', f);
                                            data.append('bucket', 'limo_petty');
                                            fetch(@js(route('form.upload-image')), {
                                                method: 'POST',
                                                headers: {
                                                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                                    'Accept': 'application/json',
                                                },
                                                body: data,
                                                credentials: 'same-origin',
                                            }).then(async (r) => {
                                                if (!r.ok) { uploadError = @js(__('Upload failed.')); return; }
                                                const j = await r.json();
                                                await $wire.set('linePhoto', j.path);
                                            }).finally(() => { uploading = false; $event.target.value = ''; });
                                       ">
                            </label>
                            <button type="button" wire:click="addLine" wire:loading.attr="disabled" class="o-btn-primary text-sm">{{ __('Add') }}</button>
                        </div>
                        <p x-show="uploadError" x-cloak class="text-xs text-red-600 sm:col-span-3 lg:col-span-6" x-text="uploadError"></p>
                        @error('lineAmount') <p class="text-xs text-red-600 sm:col-span-3 lg:col-span-6">{{ $message }}</p> @enderror
                        @error('lineDate') <p class="text-xs text-red-600 sm:col-span-3 lg:col-span-6">{{ $message }}</p> @enderror
                    </div>
                @endif
            </div>
        </div>

        <div class="space-y-4">
            <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
                <h2 class="mb-3 text-sm font-semibold text-chrome-800">{{ __('Settlement') }}</h2>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Given') }}</dt><dd class="font-semibold text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($advance->amount) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-chrome-500">{{ __('Receipts') }}</dt><dd class="font-medium text-chrome-800">{{ \App\Erp\Views\ValueFormat::money($linesTotal) }}</dd></div>
                    <div class="flex justify-between border-t border-chrome-100 pt-2">
                        <dt class="font-semibold text-chrome-700">{{ $difference > 0 ? __('Short') : ($difference < 0 ? __('Over') : __('Exact')) }}</dt>
                        <dd class="font-bold {{ $difference > 0 ? 'text-red-600' : ($difference < 0 ? 'text-emerald-700' : 'text-chrome-800') }}">
                            {{ \App\Erp\Views\ValueFormat::money(abs($difference)) }}
                        </dd>
                    </div>
                </dl>

                @if ($advance->isCleared())
                    <div class="mt-4 rounded-xl bg-chrome-50 p-3 text-xs text-chrome-600">
                        @if ($advance->shortfall > 0)
                            {{ __(':amount deducted from :driver’s salary.', ['amount' => \App\Erp\Views\ValueFormat::money($advance->shortfall), 'driver' => $advance->driver?->name ?? '—']) }}
                        @elseif ($advance->excess > 0)
                            {{ __(':amount paid back to :driver from the float.', ['amount' => \App\Erp\Views\ValueFormat::money($advance->excess), 'driver' => $advance->driver?->name ?? '—']) }}
                        @else
                            {{ __('Receipts matched the amount exactly.') }}
                        @endif
                        <div class="mt-1 text-chrome-400">{{ __('Settled by :name', ['name' => $advance->settled_by]) }} · {{ $advance->settled_at?->isoFormat('DD-MMM-YYYY') }}</div>
                    </div>
                @elseif ($difference > 0)
                    <p class="mt-4 text-xs text-chrome-500">{{ __('If settled now, :amount is deducted from the driver’s salary.', ['amount' => \App\Erp\Views\ValueFormat::money($difference)]) }}</p>
                @elseif ($difference < 0)
                    <p class="mt-4 text-xs text-chrome-500">{{ __('If settled now, :amount is paid back to the driver from the float.', ['amount' => \App\Erp\Views\ValueFormat::money(abs($difference))]) }}</p>
                @endif
            </div>
        </div>
    </div>

    {{-- Settle: the decision is spelled out BEFORE it is made. --}}
    @if ($settling)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-chrome-900/50 p-4" x-on:keydown.escape.window="$wire.closeSettle()">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl sm:p-6" x-on:click.outside="$wire.closeSettle()">
                <h2 class="text-sm font-semibold text-chrome-800">{{ __('Settle :reference', ['reference' => $advance->reference]) }}</h2>
                <p class="mt-3 text-sm text-chrome-700">
                    {{ __('Given :given · receipts :receipts.', ['given' => \App\Erp\Views\ValueFormat::money($advance->amount), 'receipts' => \App\Erp\Views\ValueFormat::money($linesTotal)]) }}
                </p>
                <p class="mt-2 text-sm font-medium {{ $difference > 0 ? 'text-red-600' : ($difference < 0 ? 'text-emerald-700' : 'text-chrome-700') }}">
                    @if ($difference > 0)
                        {{ __(':amount will be deducted from :driver’s salary.', ['amount' => \App\Erp\Views\ValueFormat::money($difference), 'driver' => $advance->driver?->name ?? '—']) }}
                    @elseif ($difference < 0)
                        {{ __(':amount will be paid back to :driver from the float.', ['amount' => \App\Erp\Views\ValueFormat::money(abs($difference)), 'driver' => $advance->driver?->name ?? '—']) }}
                    @else
                        {{ __('Receipts match the amount exactly — nothing to adjust.') }}
                    @endif
                </p>
                <p class="mt-2 text-xs text-chrome-500">{{ __('Settling locks the advance and writes every receipt into the expense ledger.') }}</p>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" wire:click="closeSettle" class="o-btn-ghost text-sm">{{ __('Close') }}</button>
                    <button type="button" wire:click="saveSettle" wire:loading.attr="disabled" class="o-btn-primary text-sm">{{ __('Settle') }}</button>
                </div>
            </div>
        </div>
    @endif

    {{-- A new spending category, made where the need appears. --}}
    @if ($addingCategory)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-chrome-900/50 p-4" x-on:keydown.escape.window="$wire.closeCategory()">
            <div class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-xl sm:p-6" x-on:click.outside="$wire.closeCategory()">
                <h2 class="text-sm font-semibold text-chrome-800">{{ __('New category') }}</h2>
                <p class="mt-1 text-xs text-chrome-500">{{ __('In the expense reports it files under “Other”, keeping its own name on every line.') }}</p>
                <div class="mt-4">
                    <label class="mb-1 block text-sm font-medium text-chrome-700">{{ __('Name') }}</label>
                    <input type="text" wire:model="newCategory" wire:keydown.enter="saveCategory" class="o-input w-full" placeholder="{{ __('e.g. Car decoration') }}">
                    @error('newCategory') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" wire:click="closeCategory" class="o-btn-ghost text-sm">{{ __('Close') }}</button>
                    <button type="button" wire:click="saveCategory" wire:loading.attr="disabled" class="o-btn-primary text-sm">{{ __('Add') }}</button>
                </div>
            </div>
        </div>
    @endif
</div>
