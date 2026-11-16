{{--
    Travel authorizations: make the letter, and see who holds one for which car.
    The car and driver come from the records; the page says what is missing
    before a letter can be made. PDF is the only output.
--}}
@php
    $lbl = 'mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500';
    $card = 'rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]';
    $standing = [
        'active' => ['label' => __('In force'), 'class' => 'bg-emerald-50 text-emerald-700 ring-emerald-100'],
        'upcoming' => ['label' => __('Not started'), 'class' => 'bg-sky-50 text-sky-700 ring-sky-100'],
        'expired' => ['label' => __('Ended'), 'class' => 'bg-chrome-100 text-chrome-500 ring-chrome-200'],
    ];
    $ready = $car !== null && $driver !== null && $carProblems === [] && $driverProblems === [];
@endphp

<div class="mx-auto max-w-6xl px-4 py-6 sm:px-6">
    <div class="mb-5">
        <h1 class="text-2xl font-bold tracking-tight text-chrome-900">{{ __('Travel authorizations') }}</h1>
        <p class="mt-1 text-sm text-chrome-500">{{ __('The letter that lets a driver take one of our cars to another Gulf country.') }}</p>
    </div>

    @if ($canMake)
        <div class="{{ $card }} mb-6" data-permit-form>
            <h2 class="mb-3 text-sm font-semibold text-chrome-800">{{ __('New authorization') }}</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="{{ $lbl }}">{{ __('Car') }} <span class="text-red-500">*</span></label>
                    <x-searchable-select wire:model.live="vehicle_id" class="o-input w-full"
                        :options="$cars->map(fn ($c) => ['value' => $c->id, 'label' => $c->name . ($c->plate_no ? ' · ' . $c->plate_no : '')])->all()"
                        :search-placeholder="__('Search name or plate…')" />
                    @error('vehicle_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @if ($car)
                        @if ($carProblems !== [])
                            <div class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700" data-car-problems>
                                @foreach ($carProblems as $problem)<p>{{ $problem }}</p>@endforeach
                                <a href="{{ url('/app/rental/vehicle/' . $car->id) }}" wire:navigate class="mt-1 inline-block font-semibold underline">{{ __('Open the car to complete it') }}</a>
                            </div>
                        @else
                            <p class="mt-2 text-xs text-chrome-500">
                                {{ $car->make }} {{ $car->model }} · {{ $car->year }} · {{ $car->color }} ·
                                {{ __('registration until :date', ['date' => $car->registration_expiry?->format('d/m/Y')]) }}
                            </p>
                        @endif
                    @endif
                </div>
                <div>
                    <label class="{{ $lbl }}">{{ __('Driver') }} <span class="text-red-500">*</span></label>
                    <x-searchable-select wire:model.live="driver_id" class="o-input w-full"
                        :options="$drivers->map(fn ($d) => ['value' => $d->id, 'label' => $d->name . ($d->cpr ? ' · ' . $d->cpr : '')])->all()"
                        :search-placeholder="__('Search name or CPR…')" />
                    @error('driver_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    @if ($driver)
                        @if ($driverProblems !== [])
                            <div class="mt-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700" data-driver-problems>
                                @foreach ($driverProblems as $problem)<p>{{ $problem }}</p>@endforeach
                                <a href="{{ url('/app/rental/driver/' . $driver->id) }}" wire:navigate class="mt-1 inline-block font-semibold underline">{{ __('Open the driver to complete it') }}</a>
                            </div>
                        @else
                            <p class="mt-2 text-xs text-chrome-500">{{ $driver->name }} · {{ __('CPR') }} {{ $driver->cpr }}</p>
                        @endif
                    @endif
                </div>
                <div>
                    <label class="{{ $lbl }}">{{ __('Date of the letter') }} <span class="text-red-500">*</span></label>
                    <x-date-field wire:model.live="issued_on" class="o-input w-full" />
                    @error('issued_on') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $lbl }}">{{ __('Valid from') }} <span class="text-red-500">*</span></label>
                    <x-date-field wire:model.live="valid_from" class="o-input w-full" />
                    @error('valid_from') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="mt-4">
                <label class="{{ $lbl }}">{{ __('For how long') }}</label>
                <div class="flex flex-wrap gap-1.5" data-permit-periods>
                    @foreach ($periods as $key => $label)
                        <button type="button" wire:click="setPeriod('{{ $key }}')"
                            class="rounded-lg px-3 py-1.5 text-sm font-semibold transition {{ $period === $key ? 'bg-primary-400 text-chrome-900' : 'bg-chrome-100 text-chrome-600 hover:bg-chrome-200' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
                @error('period') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            @if ($window)
                <div class="mt-4 rounded-lg px-3 py-2 text-sm {{ $window['shortened'] ? 'bg-amber-50 text-amber-800' : 'bg-chrome-50 text-chrome-700' }}" data-permit-window>
                    {{ __('Valid until :date', ['date' => $window['to']->format('d/m/Y')]) }}
                    @if ($window['shortened'])
                        <span class="block text-xs">{{ __('The period you chose runs past the car\'s registration, so the authorization ends on its last day.') }}</span>
                    @endif
                </div>
            @endif

            @if ($held)
                <div class="mt-3 rounded-lg bg-sky-50 px-3 py-2 text-sm text-sky-800" data-permit-held>
                    {{ __('This driver already holds an authorization for this car until :date (:ref).', ['date' => $held->valid_to->format('d/m/Y'), 'ref' => (string) $held->reference]) }}
                </div>
            @endif

            @error('owner') <p class="mt-3 text-sm text-red-600">{{ $message }}</p> @enderror

            <div class="mt-4">
                <button type="button" wire:click="issue" wire:loading.attr="disabled" @disabled(! $ready)
                    class="o-btn-primary disabled:cursor-not-allowed disabled:opacity-50">
                    {{ __('Make the authorization') }}
                </button>
                @unless ($ready)
                    <span class="ms-2 text-xs text-chrome-400">{{ __('Choose a car and a driver whose details are complete.') }}</span>
                @endunless
            </div>
        </div>
    @endif

    {{-- The letters --}}
    <div class="{{ $card }}">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-sm font-semibold text-chrome-800">{{ __('Authorizations made') }}</h2>
            <div class="flex flex-wrap items-center gap-3">
                <label class="flex items-center gap-2 text-sm text-chrome-600">
                    <input type="checkbox" wire:model.live="current" class="rounded border-chrome-300">
                    {{ __('Only those still valid') }}
                </label>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search driver, CPR or plate…') }}" class="o-input w-64 text-sm">
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[52rem] text-sm">
                <thead>
                    <tr class="border-b border-chrome-200 text-[11px] font-bold uppercase tracking-wider text-chrome-400">
                        <th class="py-2 text-start">{{ __('No.') }}</th>
                        <th class="py-2 text-start">{{ __('Driver') }}</th>
                        <th class="py-2 text-start">{{ __('Car') }}</th>
                        <th class="py-2 text-start">{{ __('Valid from') }}</th>
                        <th class="py-2 text-start">{{ __('Valid until') }}</th>
                        <th class="py-2 text-start">{{ __('Status') }}</th>
                        <th class="py-2 text-end">{{ __('PDF') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-chrome-100">
                    @forelse ($letters as $letter)
                        @php $s = $standing[$letter->standing()]; @endphp
                        <tr wire:key="permit-{{ $letter->id }}" data-permit="{{ $letter->reference }}">
                            <td class="py-2.5 font-medium text-chrome-800">{{ $letter->reference }}</td>
                            <td class="py-2.5">
                                <div class="font-medium text-chrome-900">{{ $letter->driver_name }}</div>
                                <div class="text-xs text-chrome-400">{{ __('CPR') }} {{ $letter->driver_cpr }}</div>
                            </td>
                            <td class="py-2.5">
                                <div class="text-chrome-800">{{ $letter->make }} {{ $letter->model }}</div>
                                <div class="text-xs text-chrome-400">{{ $letter->plate_no }}</div>
                            </td>
                            <td class="py-2.5 tabular-nums text-chrome-600">{{ $letter->valid_from->format('d/m/Y') }}</td>
                            <td class="py-2.5 tabular-nums text-chrome-600">{{ $letter->valid_to->format('d/m/Y') }}</td>
                            <td class="py-2.5">
                                <span class="inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide ring-1 {{ $s['class'] }}">{{ $s['label'] }}</span>
                            </td>
                            <td class="py-2.5 text-end whitespace-nowrap">
                                <a href="{{ url('/app/rental/travel-permit/' . $letter->id . '/pdf') }}" class="rounded-md px-2 py-1 text-xs font-semibold text-chrome-700 ring-1 ring-chrome-200 hover:bg-chrome-50">{{ __('Without stamp') }}</a>
                                @if ($canStamp)
                                    @if ($hasStamp)
                                        <a href="{{ url('/app/rental/travel-permit/' . $letter->id . '/pdf?stamp=1') }}" data-permit-stamped
                                           class="ms-1 rounded-md bg-primary-400 px-2 py-1 text-xs font-semibold text-chrome-900 hover:bg-primary-500">{{ __('With stamp') }}</a>
                                    @else
                                        <span class="ms-1 text-xs text-chrome-400">{{ __('No stamp uploaded') }}</span>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-8 text-center text-sm text-chrome-400">{{ __('No authorizations yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $letters->links() }}</div>
    </div>

    {{-- The owner's settings: what is printed, the stamp and signature, and who may use them. --}}
    @if ($isOwner)
        <div class="{{ $card }} mt-6" data-permit-settings>
            <h2 class="mb-1 text-sm font-semibold text-chrome-800">{{ __('Stamp, signature and who may use them') }}</h2>
            <p class="mb-4 text-xs text-chrome-500">{{ __('Only you see this box. The stamp and signature are kept privately and appear only on a letter printed "With stamp".') }}</p>

            <div class="grid gap-6 md:grid-cols-2">
                <div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="{{ $lbl }}">{{ __('Owner name on the letter') }}</label>
                            <input type="text" wire:model="ownerName" class="o-input w-full text-sm">
                            @error('ownerName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="{{ $lbl }}">{{ __('Owner number (CR)') }}</label>
                            <input type="text" wire:model="ownerNo" class="o-input w-full text-sm">
                            @error('ownerNo') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <button type="button" wire:click="saveOwner" class="o-btn-primary mt-3 text-sm">{{ __('Save') }}</button>

                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        @foreach (['stamp' => [__('Stamp'), $hasStamp], 'signature' => [__('Signature'), $hasSignature]] as $kind => [$kindLabel, $has])
                            <div x-data="{ busy: false, error: '',
                                    async up(e) {
                                        const file = e.target.files[0]; if (!file) return;
                                        this.busy = true; this.error = '';
                                        const data = new FormData(); data.append('file', file); data.append('kind', @js($kind));
                                        try {
                                            const r = await fetch(@js(url('/app/rental/travel-permit/artwork')), { method: 'POST', body: data, credentials: 'same-origin',
                                                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' } });
                                            if (!r.ok) { const j = await r.json().catch(() => ({})); this.error = (j.errors && Object.values(j.errors)[0][0]) || j.message || @js(__('Upload failed.')); return; }
                                            $wire.$refresh();
                                        } catch (err) { this.error = err.message || @js(__('Upload failed.')); } finally { this.busy = false; e.target.value = ''; }
                                    } }">
                                <label class="{{ $lbl }}">{{ $kindLabel }}</label>
                                @if ($has)
                                    <img src="{{ url('/app/rental/travel-permit/artwork/' . $kind) }}?v={{ now()->timestamp }}" alt="{{ $kindLabel }}" class="mb-2 max-h-28 rounded ring-1 ring-chrome-200">
                                @else
                                    <p class="mb-2 text-xs text-amber-700">{{ __('Not uploaded yet.') }}</p>
                                @endif
                                <input type="file" accept="image/png,image/jpeg,image/webp" @change="up($event)" :disabled="busy" class="block w-full text-xs">
                                <p x-show="busy" class="mt-1 text-xs text-chrome-500">{{ __('Uploading…') }}</p>
                                <p x-show="error" x-text="error" class="mt-1 text-xs text-red-600"></p>
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-2 text-[11px] text-chrome-400">{{ __('A photo or scan (PNG or JPG) on a white or clear background works best.') }}</p>
                </div>

                <div>
                    <label class="{{ $lbl }}">{{ __('Who may print with the stamp') }}</label>
                    <ul class="max-h-72 divide-y divide-chrome-100 overflow-y-auto rounded-lg ring-1 ring-chrome-200">
                        @foreach ($staff as $member)
                            <li wire:key="stamper-{{ $member->id }}" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                                <span class="min-w-0">
                                    <span class="block truncate font-medium text-chrome-800">{{ $member->name }}</span>
                                    <span class="block truncate text-xs text-chrome-400">{{ $member->email }}</span>
                                </span>
                                @if ($member->isSuperAdmin())
                                    <span class="text-xs font-semibold text-emerald-700">{{ __('Owner, always') }}</span>
                                @else
                                    <input type="checkbox" @checked($member->canStampTravelPermits())
                                        wire:click="setStamper({{ $member->id }}, {{ $member->canStampTravelPermits() ? 'false' : 'true' }})"
                                        class="rounded border-chrome-300">
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    @endif
</div>
