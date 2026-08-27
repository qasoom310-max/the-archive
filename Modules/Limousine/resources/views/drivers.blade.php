<div class="mx-auto max-w-7xl p-4 sm:p-6">
    {{-- The same people drive for both apps, so this list is shared with Rent A
         Car: a driver added or corrected here shows up there too. --}}
    <x-page-header :title="__('Drivers')" :subtitle="__('Shared with Rent A Car.')" icon="user" accent="indigo">
        <x-slot:actions>
            <a href="{{ url('/app/limousine/driver/new') }}" wire:navigate class="o-btn-primary">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                {{ __('New') }}
            </a>
        </x-slot:actions>
    </x-page-header>

    <livewire:views.list-view
        :model="\Modules\Limousine\Models\LimoDriver::class"
        model-key="limousine.driver"
        title="Drivers"
        :key="'limo-drivers'" />
</div>
