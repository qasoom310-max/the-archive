<div class="mx-auto max-w-2xl p-4 sm:p-6">
    <div class="mb-4 flex items-center gap-2 text-sm text-chrome-500">
        <a href="{{ url('/app/' . $module) }}" wire:navigate class="hover:text-primary-700">{{ $moduleLabel }}</a>
        <span>/</span>
        <span class="font-medium text-chrome-700">{{ __('Settings') }}</span>
    </div>

    <div class="mb-5">
        <h1 class="text-xl font-bold text-chrome-900">{{ $moduleLabel }} — {{ __('Features') }}</h1>
        <p class="text-sm text-chrome-500">
            {{ __('Turn parts of this app on or off for this database. Changes apply immediately.') }}
        </p>
    </div>

    @if ($saved)
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200">
            {{ __('Saved.') }}
        </div>
    @endif

    <form wire:submit="save" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
        <div class="divide-y divide-chrome-100">
            @forelse ($features as $feature)
                <label wire:key="feat-{{ $feature->value }}"
                    class="flex cursor-pointer items-center justify-between gap-4 py-3">
                    <span class="min-w-0">
                        <span class="block text-sm font-medium text-chrome-800">{{ __($feature->label()) }}</span>
                        @if ($feature->description() !== '')
                            <span class="mt-0.5 block text-xs text-chrome-500">{{ __($feature->description()) }}</span>
                        @endif
                    </span>
                    {{-- iOS-style switch --}}
                    <span class="relative inline-flex shrink-0">
                        <input type="checkbox" wire:model="toggles.{{ $feature->value }}" class="peer sr-only">
                        <span class="h-6 w-11 rounded-full bg-chrome-300 transition-colors peer-checked:bg-primary-500"></span>
                        <span class="absolute start-0.5 top-0.5 size-5 rounded-full bg-white transition-transform peer-checked:translate-x-5 rtl:peer-checked:-translate-x-5"></span>
                    </span>
                </label>
            @empty
                <p class="py-6 text-center text-sm text-chrome-400">{{ __('No configurable features for this app.') }}</p>
            @endforelse
        </div>

        <div class="mt-5 flex justify-end border-t border-chrome-100 pt-4">
            <button type="submit" class="o-btn-primary" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">{{ __('Save') }}</span>
                <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
            </button>
        </div>
    </form>
</div>
