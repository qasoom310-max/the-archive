<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <x-page-header :title="__('Locations')" :subtitle="__('Pickup & dropoff points.')" icon="pin" accent="indigo">
        <x-slot:actions>
            @if ($canManage)
                <button type="button" onclick="document.getElementById('import-locations').classList.toggle('hidden')" class="o-btn-ghost">{{ __('Import') }}</button>
            @endif
            <a href="{{ url('/app/limousine/location/new') }}" wire:navigate class="o-btn-primary">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                {{ __('New') }}
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- Import locations from a CSV (managers). Direct POST — Hostinger-safe.
         The expected columns are the same shape this screen's own export
         prints. A location already on file (by name) is skipped. --}}
    @if ($canManage)
        <div id="import-locations" class="mb-4 {{ $errors->any() ? '' : 'hidden' }} rounded-2xl border border-dashed border-chrome-300 bg-white p-4">
            <h3 class="mb-1 text-sm font-semibold text-chrome-800">{{ __('Import locations (CSV)') }}</h3>
            <p class="mb-3 text-xs text-chrome-500">{{ __('Columns: Name, Area, Notes, Active. Other columns are ignored. A location with the same name is skipped.') }}</p>
            <form method="POST" action="{{ url('/app/limousine/location/import') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
                @csrf
                <input type="file" name="file" accept=".csv,text/csv,text/plain" required class="text-sm">
                <button type="submit" class="o-btn-primary text-sm">{{ __('Import') }}</button>
            </form>
            @error('file')<p class="mt-2 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
            @if (session('toast'))
                <p class="mt-2 text-xs font-medium text-emerald-600">{{ session('toast') }}</p>
            @endif
        </div>
    @endif

    {{-- Where trips are picked up from most, counted from the bookings. A
         place already on the list opens its record; one the office keeps
         typing by hand has an Add button. --}}
    @if ($topPickups !== [])
        <div class="mb-4 rounded-2xl border border-chrome-200 bg-white p-4" data-top-pickups>
            <h3 class="text-sm font-semibold text-chrome-800">{{ __('Most used pick-up places') }}</h3>
            <p class="mb-3 text-xs text-chrome-500">{{ __('Counted from the bookings. Cancelled trips are left out.') }}</p>
            @if (session('place_added'))
                <p class="mb-3 text-xs font-medium text-emerald-600" data-place-added>{{ session('place_added') }}</p>
            @endif
            <ol class="grid gap-2 sm:grid-cols-2">
                @foreach ($topPickups as $i => $place)
                    <li class="flex items-center gap-3 rounded-xl border border-chrome-200 px-3 py-2" data-top-pickup wire:key="top-pickup-{{ $i }}-{{ $place['key'] }}">
                        <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-chrome-100 text-xs font-semibold text-chrome-700">{{ $i + 1 }}</span>
                        <div class="min-w-0 flex-1">
                            @if ($place['location_id'] !== null)
                                <a href="{{ url('/app/limousine/location/' . $place['location_id']) }}" wire:navigate
                                   class="block truncate text-sm font-medium text-primary-700 hover:underline">{{ $place['name'] }}</a>
                            @else
                                <span class="block truncate text-sm font-medium text-chrome-900">{{ $place['name'] }}</span>
                            @endif
                            <span class="text-xs text-chrome-500">
                                {{ $place['country'] === \Modules\Limousine\Models\LimoLocation::OTHER_COUNTRY ? __('Other') : $place['country'] }}
                                · {{ __(':count trips', ['count' => $place['trips']]) }}
                            </span>
                        </div>
                        @if ($place['location_id'] !== null)
                            <span class="shrink-0 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-medium text-emerald-700">{{ __('On the list') }}</span>
                        @elseif ($canAdd)
                            <button type="button" wire:click="addPlace(@js($place['key']))" wire:loading.attr="disabled"
                                    class="shrink-0 rounded-lg bg-primary-400 px-2.5 py-1 text-xs font-semibold text-chrome-900 transition hover:bg-primary-500"
                                    data-add-place>{{ __('Add') }}</button>
                        @else
                            <span class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-700">{{ __('Not on the list') }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </div>
    @endif

    <livewire:views.list-view
        :model="\Modules\Limousine\Models\LimoLocation::class"
        model-key="limousine.location"
        title="Locations"
        :key="'limo-locations-' . $listVersion" />
</div>
