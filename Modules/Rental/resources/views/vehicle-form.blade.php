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
            </div>
            @if ($vehicle->status === 'maintenance' && $canManage)
                <button wire:click="returnToService"
                    wire:confirm="{{ __('Return this car to the available fleet?') }}"
                    class="o-btn-primary text-sm">{{ __('Return to service') }}</button>
            @endif
        </div>

        {{-- Monthly sales target vs actual earnings this month. --}}
        @if ($vehicle->monthly_target > 0)
            @php
                $target = (float) $vehicle->monthly_target;
                $pct = $target > 0 ? min(100, (int) round($earnedThisMonth / $target * 100)) : 0;
                $hit = $earnedThisMonth >= $target;
                $bar = $hit ? 'bg-emerald-500' : ($pct >= 60 ? 'bg-sky-500' : 'bg-amber-500');
            @endphp
            <div class="mb-5 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/[0.06]">
                <div class="mb-2 flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <h3 class="text-sm font-semibold text-chrome-800">{{ __('Monthly sales target') }}</h3>
                        <p class="text-xs text-chrome-400">{{ __('Earnings this month vs target') }}</p>
                    </div>
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
