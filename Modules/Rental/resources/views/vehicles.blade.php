<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <x-page-header :title="__('Cars')" :subtitle="__('Your rental fleet.')" icon="car" accent="primary">
        <x-slot:actions>
            @if ($canManage)
                <button type="button" onclick="document.getElementById('import-cars').classList.toggle('hidden')" class="o-btn-ghost">{{ __('Import') }}</button>
            @endif
            <a href="{{ url('/app/rental/vehicle/new') }}" wire:navigate class="o-btn-primary">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                {{ __('New') }}
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- Import cars from a CSV (managers). Direct POST — Hostinger-safe. --}}
    @if ($canManage)
        <div id="import-cars" class="mb-4 {{ $errors->any() ? '' : 'hidden' }} rounded-2xl border border-dashed border-chrome-300 bg-white p-4">
            <h3 class="mb-1 text-sm font-semibold text-chrome-800">{{ __('Import cars (CSV)') }}</h3>
            <p class="mb-3 text-xs text-chrome-500">{{ __('Columns: Name, Reg.No, Year, Color, Type, Status. Other columns are ignored. Duplicate plates are skipped.') }}</p>
            <form method="POST" action="{{ url('/app/rental/vehicle/import') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
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

    <livewire:views.list-view
        :model="\Modules\Rental\Models\Vehicle::class"
        model-key="rental.vehicle"
        title="Cars"
        :key="'rental-vehicles'" />
</div>
