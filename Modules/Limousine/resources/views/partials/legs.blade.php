{{-- Shared trip-leg editor for the Limousine booking + quotation forms. The host
     component must expose: $legs (array), addLeg(), removeLeg($i); and pass the
     view vars $serviceTypes, $rateBasisOptions, $vehicleOptions, $locationNames. --}}
@php $lbl = 'mb-1 block text-sm font-medium text-chrome-700'; @endphp

{{-- One datalist of saved locations, reused by every From/To input. --}}
<datalist id="limo-locations">
    @foreach ($locationNames as $name)<option value="{{ $name }}"></option>@endforeach
</datalist>

<div class="space-y-4">
    @error('legs') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
    @foreach ($legs as $i => $leg)
        @php
            $isChauffeur = ($leg['service_type'] ?? 'transfer') === 'chauffeur';
            $basis = $leg['rate_basis'] ?? 'trip';
            $rate = (float) ($leg['rate'] === '' ? 0 : $leg['rate']);
            $hours = (float) ($leg['hours'] === '' ? 0 : $leg['hours']);
            $days = max(1, (int) ($leg['days'] === '' ? 1 : $leg['days']));
            $gross = match ($basis) { 'hour' => $rate * $hours * $days, 'day' => $rate * $days, default => $rate };
            $net = max(0, $gross - (float) ($leg['discount'] === '' ? 0 : $leg['discount'])) + (float) ($leg['vat'] === '' ? 0 : $leg['vat']);
        @endphp
        <div wire:key="leg-{{ $i }}" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06] sm:p-6">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-chrome-800">{{ __('Leg') }} {{ $i + 1 }}</h3>
                <div class="flex items-center gap-3">
                    {{-- Service type toggle (buttons → reliable field switch) --}}
                    <div class="flex gap-1">
                        @foreach ($serviceTypes as $opt)
                            @php $active = ($leg['service_type'] ?? 'transfer') === $opt['value']; @endphp
                            <button type="button" wire:key="leg-{{ $i }}-st-{{ $opt['value'] }}"
                                wire:click="$set('legs.{{ $i }}.service_type', '{{ $opt['value'] }}')"
                                class="rounded-lg border px-2.5 py-1 text-xs transition {{ $active ? 'border-primary-500 bg-primary-50 font-medium text-primary-700' : 'border-chrome-200 text-chrome-600 hover:bg-chrome-50' }}">
                                {{ __($opt['label']) }}
                            </button>
                        @endforeach
                    </div>
                    @if (count($legs) > 1)
                        <button type="button" wire:click="removeLeg({{ $i }})" class="inline-flex items-center gap-1 text-xs font-medium text-red-600 hover:text-red-700">
                            <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.75 1a1 1 0 0 0-.96.72L7.5 3H4a1 1 0 0 0 0 2h12a1 1 0 1 0 0-2h-3.5l-.29-1.28A1 1 0 0 0 11.25 1h-2.5ZM5.06 7l.66 9.24A2 2 0 0 0 7.72 18h4.56a2 2 0 0 0 2-1.76L14.94 7H5.06Z" clip-rule="evenodd"/></svg>
                            {{ __('Remove') }}
                        </button>
                    @endif
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="{{ $lbl }}">{{ __('From') }} *</label>
                    <input type="text" list="limo-locations" wire:model="legs.{{ $i }}.from_location" class="o-input w-full" placeholder="{{ __('e.g. Bahrain Airport') }}">
                    @error('legs.'.$i.'.from_location') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                @if ($isChauffeur)
                    <div>
                        <label class="{{ $lbl }}">{{ __('Start date & time') }} *</label>
                        <input type="datetime-local" wire:model.live="legs.{{ $i }}.start_at" class="o-input w-full">
                        @error('legs.'.$i.'.start_at') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $lbl }}">{{ __('Hours per day') }} *</label>
                        <input type="number" step="0.5" min="0" wire:model.live="legs.{{ $i }}.hours" class="o-input w-full" placeholder="{{ __('e.g. 8') }}">
                        @error('legs.'.$i.'.hours') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $lbl }}">{{ __('Number of days') }} *</label>
                        <input type="number" min="1" wire:model.live="legs.{{ $i }}.days" class="o-input w-full">
                        @error('legs.'.$i.'.days') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div>
                        <label class="{{ $lbl }}">{{ __('To') }} *</label>
                        <input type="text" list="limo-locations" wire:model="legs.{{ $i }}.to_location" class="o-input w-full" placeholder="{{ __('e.g. Manama') }}">
                        @error('legs.'.$i.'.to_location') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="{{ $lbl }}">{{ __('Date & time') }} *</label>
                        <input type="datetime-local" wire:model.live="legs.{{ $i }}.start_at" class="o-input w-full">
                        @error('legs.'.$i.'.start_at') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                @endif

                {{-- The car is not chosen while the booking is being taken — nobody
                     knows yet which vehicle will run it. The host decides when the
                     picker appears ($showCar); it defaults to ON so the quotation
                     form, which shares this partial, is unaffected. Not marked
                     required either: BookingForm::start() enforces it at dispatch. --}}
                @if ($showCar ?? true)
                    <div>
                        <label class="{{ $lbl }}">{{ __('Car') }}</label>
                        <select wire:model="legs.{{ $i }}.car_id" class="o-input w-full">
                            <option value="">{{ count($carOptions) ? __('— Select —') : __('No cars available') }}</option>
                            @foreach ($carOptions as $opt)<option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>@endforeach
                        </select>
                        @error('legs.'.$i.'.car_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                @endif
                <div>
                    <label class="{{ $lbl }}">{{ __('Car details') }}</label>
                    <input type="text" wire:model="legs.{{ $i }}.car_details" class="o-input w-full" placeholder="{{ __('Any note about the car') }}">
                </div>

                <div>
                    <label class="{{ $lbl }}">{{ __('Rate (BHD)') }} *</label>
                    <input type="number" step="0.001" min="0" wire:model.live="legs.{{ $i }}.rate" class="o-input w-full">
                    @error('legs.'.$i.'.rate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $lbl }}">{{ __('Rate basis') }} *</label>
                    <select wire:model.live="legs.{{ $i }}.rate_basis" class="o-input w-full">
                        @foreach ($rateBasisOptions as $opt)<option value="{{ $opt['value'] }}">{{ __($opt['label']) }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $lbl }}">{{ __('Discount (BHD)') }}</label>
                    <input type="number" step="0.001" min="0" wire:model.live="legs.{{ $i }}.discount" class="o-input w-full">
                </div>
                <div>
                    <label class="{{ $lbl }}">{{ __('VAT (BHD)') }}</label>
                    <input type="number" step="0.001" min="0" wire:model.live="legs.{{ $i }}.vat" class="o-input w-full">
                </div>
            </div>

            {{-- Chauffeur day-by-day schedule preview (auto-generated). --}}
            @if ($isChauffeur && ($leg['start_at'] ?? '') !== '')
                @php $start = \Illuminate\Support\Carbon::parse($leg['start_at']); @endphp
                <div class="mt-4 rounded-lg bg-chrome-50 p-3">
                    <p class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Schedule') }} · {{ $days }} {{ __('day(s)') }}</p>
                    <div class="flex flex-wrap gap-1.5">
                        @for ($d = 0; $d < min($days, 60); $d++)
                            <span class="rounded-md bg-white px-2 py-1 text-xs text-chrome-600 ring-1 ring-chrome-200">
                                {{ $start->copy()->addDays($d)->isoFormat('ddd D MMM') }}@if ($leg['hours'] !== '') · {{ rtrim(rtrim(number_format((float) $leg['hours'], 1), '0'), '.') }}h @endif
                            </span>
                        @endfor
                    </div>
                </div>
            @endif

            {{-- Live leg total + net --}}
            <div class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-chrome-100 pt-3 text-sm">
                <span class="text-chrome-500">{{ __('Leg total') }} <span class="font-medium text-chrome-700">{{ \App\Erp\Views\ValueFormat::money($gross) }}</span></span>
                <span class="flex items-center gap-2 rounded-lg bg-primary-50 px-3 py-1.5"><span class="font-semibold text-primary-800">{{ __('Net') }}</span><span class="font-bold text-primary-700">{{ \App\Erp\Views\ValueFormat::money($net) }}</span></span>
            </div>
        </div>
    @endforeach

    <button type="button" wire:click="addLeg" class="flex w-full items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-chrome-300 bg-white/60 py-3 text-sm font-medium text-chrome-600 hover:border-primary-400 hover:text-primary-700">
        <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
        {{ __('Add another leg') }}
    </button>
</div>
