<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <x-form-breadcrumb :parent="__('Cars')" :parent-url="url('/app/rental/vehicle')" :current="$vehicle?->name ?? __('New car')" />

    {{-- Status panel — bring a car back from maintenance. --}}
    @if ($vehicle)
        @php
            $statusBadge = [
                'available' => 'bg-emerald-100 text-emerald-700',
                'rented' => 'bg-sky-100 text-sky-700',
                'maintenance' => 'bg-amber-100 text-amber-700',
                'reserved' => 'bg-violet-100 text-violet-700',
            ][$vehicle->status] ?? 'bg-chrome-200 text-chrome-700';
        @endphp
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/[0.06]">
            <div class="flex items-center gap-2.5">
                <span class="text-sm font-semibold text-chrome-800">{{ $vehicle->displayName() }}</span>
                <span class="rounded-full px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $statusBadge }}">{{ __(ucfirst($vehicle->status)) }}</span>
                @if ($vehicle->is_outside)
                    <span class="rounded-full bg-orange-100 px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-orange-700">{{ __('Outside') }}</span>
                @endif
            </div>
            @if ($vehicle->status === 'maintenance' && $canManage)
                <button wire:click="returnToService"
                    wire:confirm="{{ __('Return this car to the available fleet?') }}"
                    class="o-btn-primary text-sm">{{ __('Return to service') }}</button>
            @endif
        </div>

        {{-- Monthly sales target: set it inline, and track this month's earnings. --}}
        @if ($vehicle->monthly_target > 0 || $canManage)
            <div class="mb-5 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/[0.06]">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold text-chrome-800">{{ __('Sales targets') }}</h3>
                    @if ($canManage)
                        <div class="flex items-end gap-2">
                            <div>
                                <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-chrome-400">{{ __('Monthly (BHD)') }}</label>
                                <input type="number" min="0" step="0.001" wire:model="targetInput" placeholder="0.000" class="o-input w-32 text-sm">
                            </div>
                            <div>
                                {{-- A year is not twelve identical months: the car is off
                                     the road for service and the trade has seasons. Blank
                                     falls back to 12 × the monthly, and says so. --}}
                                <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-chrome-400">{{ __('Yearly (BHD)') }}</label>
                                <input type="number" min="0" step="0.001" wire:model="yearlyTargetInput" placeholder="{{ __('12 × monthly') }}" class="o-input w-32 text-sm">
                            </div>
                            <button wire:click="saveTarget" class="o-btn-primary text-sm">{{ __('Save') }}</button>
                        </div>
                    @endif
                </div>

                @if ($vehicle->monthly_target > 0)
                    @php
                        $target = (float) $vehicle->monthly_target;
                        $pct = $target > 0 ? min(100, (int) round($earnedThisMonth / $target * 100)) : 0;
                        $hit = $earnedThisMonth >= $target;
                        $bar = $hit ? 'bg-emerald-500' : ($pct >= 60 ? 'bg-sky-500' : 'bg-amber-500');
                    @endphp
                    <div class="mb-1.5 flex items-end justify-between gap-2">
                        <p class="text-xs text-chrome-400">{{ __('Earnings this month vs target') }}</p>
                        <div class="text-end">
                            <span class="text-lg font-bold {{ $hit ? 'text-emerald-600' : 'text-chrome-800' }}">{{ \App\Erp\Views\ValueFormat::money($earnedThisMonth) }}</span>
                            <span class="text-sm text-chrome-400">/ {{ \App\Erp\Views\ValueFormat::money($target) }}</span>
                        </div>
                    </div>
                    <div class="h-2.5 w-full overflow-hidden rounded-full bg-chrome-100">
                        <div class="h-full rounded-full {{ $bar }}" style="width: {{ $pct }}%"></div>
                    </div>
                    <p class="mt-1.5 text-xs {{ $hit ? 'text-emerald-600' : 'text-chrome-500' }}">
                        @if ($hit)
                            {{ __('Target reached 🎉') }}
                        @else
                            {{ __(':pct% — :amount to go', ['pct' => $pct, 'amount' => \App\Erp\Views\ValueFormat::money(max(0, $target - $earnedThisMonth))]) }}
                        @endif
                    </p>
                @else
                    <p class="text-xs text-chrome-400">{{ __('No target set yet.') }}</p>
                @endif
            </div>
        @endif

        {{-- Cost & documents — for the accountant (chiefly outside / rented-in cars). --}}
        @if ($canSeeCost)
            <div class="mb-5 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/[0.06]"
                 x-data="{ busy:'', err:'', async up(e, prop){ const f=e.target.files[0]; if(!f)return; this.busy=prop; this.err=''; const d=new FormData(); d.append('file',f); d.append('bucket','rental_vehicles'); try{ const r=await fetch(@js(route('form.upload-file')),{method:'POST',headers:{'X-CSRF-TOKEN':@js(csrf_token())},body:d}); const j=await r.json(); if(!r.ok){this.err=(j.message||'Upload failed');return;} $wire.set(prop, j.path); }catch(_){this.err='Upload failed';}finally{this.busy='';} } }">
                <h3 class="mb-1 text-sm font-semibold text-chrome-800">{{ __('Cost & documents') }}</h3>
                <p class="mb-3 text-xs text-chrome-500">{{ __('For outside / rented-in cars: what you pay for the car, plus the vendor invoice and original agreement — so revenue is the markup, not the full amount.') }}</p>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-chrome-400">{{ __('Purchase / cost price (BHD)') }}</label>
                        <input type="number" min="0" step="0.001" wire:model="purchaseInput" placeholder="0.000" class="o-input w-full text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-chrome-400">{{ __('Invoice copy') }}</label>
                        <input type="file" accept=".pdf,image/*" @change="up($event,'invoicePath')" class="block w-full text-xs">
                        <template x-if="busy==='invoicePath'"><span class="text-[11px] text-chrome-400">{{ __('Uploading…') }}</span></template>
                        @if ($invoicePath)
                            <span class="text-[11px] text-emerald-600">{{ __('Ready — save to attach.') }}</span>
                        @elseif ($vehicle->purchase_invoice)
                            <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($vehicle->purchase_invoice) }}" target="_blank" rel="noopener" class="text-[11px] text-primary-700 hover:underline">{{ __('View current') }}</a>
                        @endif
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-chrome-400">{{ __('Original agreement copy') }}</label>
                        <input type="file" accept=".pdf,image/*" @change="up($event,'agreementPath')" class="block w-full text-xs">
                        <template x-if="busy==='agreementPath'"><span class="text-[11px] text-chrome-400">{{ __('Uploading…') }}</span></template>
                        @if ($agreementPath)
                            <span class="text-[11px] text-emerald-600">{{ __('Ready — save to attach.') }}</span>
                        @elseif ($vehicle->agreement_copy)
                            <a href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($vehicle->agreement_copy) }}" target="_blank" rel="noopener" class="text-[11px] text-primary-700 hover:underline">{{ __('View current') }}</a>
                        @endif
                    </div>
                </div>
                <label class="mt-3 flex items-center gap-2 text-sm text-chrome-700">
                    <input type="checkbox" wire:model="isOutsideInput" class="rounded border-chrome-300">
                    {{ __('This is an outside (rented-in) car — keep it out of the owned-fleet count.') }}
                </label>
                <p x-show="err" x-text="err" class="mt-2 text-xs text-red-600"></p>
                <button wire:click="saveCost" class="o-btn-primary mt-3 text-sm">{{ __('Save') }}</button>
            </div>
        @endif
    @endif

    <livewire:views.form-view
        :model="\Modules\Rental\Models\Vehicle::class"
        model-key="rental.vehicle"
        :record-id="$vehicle?->id"
        title="{{ $vehicle ? 'Edit car' : 'New car' }}"
        redirect-to="{{ url('/app/rental/vehicle') }}"
        :key="'rental-vehicle-form-'.($vehicle?->id ?? 'new')" />
</div>
