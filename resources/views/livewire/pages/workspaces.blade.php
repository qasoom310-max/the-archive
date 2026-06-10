<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <div class="mb-5">
        <h1 class="text-xl font-bold text-chrome-900">{{ __('My databases') }}</h1>
        <p class="text-sm text-chrome-500">
            {{ __('Each database is a separate, isolated ERP with all features. Switch between them any time — your Main database is your current data.') }}
        </p>
    </div>

    @if (session('workspace_status'))
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200">
            {{ session('workspace_status') }}
        </div>
    @endif

    {{-- Create --}}
    <div class="mb-6 rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
        <h2 class="text-sm font-semibold text-chrome-800">{{ __('Create a new database') }}</h2>
        <p class="mt-1 text-xs text-chrome-400">{{ __('A fresh ERP with all modules installed and your admin login. Takes a few seconds to build.') }}</p>
        <form wire:submit="create" class="mt-3 flex flex-wrap gap-2">
            <input type="text" wire:model="newName" placeholder="{{ __('e.g. Second branch') }}"
                class="o-input max-w-xs text-sm" autocomplete="off" wire:loading.attr="disabled" wire:target="create">
            <button type="submit" class="o-btn-primary text-sm" wire:loading.attr="disabled" wire:target="create">
                <span wire:loading.remove wire:target="create">{{ __('Create database') }}</span>
                <span wire:loading wire:target="create">{{ __('Building…') }}</span>
            </button>
        </form>
        @error('newName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    </div>

    {{-- List --}}
    <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
        <ul class="divide-y divide-chrome-100">
            @foreach ($workspaces as $workspace)
                <li wire:key="ws-{{ $workspace->id }}" class="flex items-center justify-between gap-3 px-5 py-3">
                    <div class="flex min-w-0 items-center gap-3">
                        <span @class([
                            'flex size-9 shrink-0 items-center justify-center rounded-lg',
                            'bg-primary-400 text-chrome-900' => $workspace->id === $currentId,
                            'bg-chrome-100 text-chrome-500' => $workspace->id !== $currentId,
                        ])>
                            <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 1c-3.866 0-7 1.343-7 3v12c0 1.657 3.134 3 7 3s7-1.343 7-3V4c0-1.657-3.134-3-7-3Zm5 15c0 .35-1.793 1.5-5 1.5S5 16.35 5 16v-2.05c1.298.66 3.107 1.05 5 1.05s3.702-.39 5-1.05V16Zm0-4c0 .35-1.793 1.5-5 1.5S5 12.35 5 12V9.95C6.298 10.61 8.107 11 10 11s3.702-.39 5-1.05V12Zm-5-3C6.793 9 5 7.85 5 7.5V5.95C6.298 6.61 8.107 7 10 7s3.702-.39 5-1.05V7.5c0 .35-1.793 1.5-5 1.5Z"/></svg>
                        </span>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-chrome-800">
                                {{ $workspace->name }}
                                @if ($workspace->is_main)
                                    <span class="ms-1 rounded bg-chrome-100 px-1.5 py-0.5 text-[10px] font-medium text-chrome-500">{{ __('Main') }}</span>
                                @endif
                            </p>
                            <p class="text-xs text-chrome-400">
                                @if ($workspace->id === $currentId)
                                    <span class="font-medium text-emerald-600">{{ __('Active') }}</span>
                                @else
                                    {{ $workspace->is_main ? __('Your current data') : __('Separate database') }}
                                @endif
                            </p>
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        @if ($workspace->id === $currentId)
                            <span class="o-chip bg-emerald-50 text-emerald-600">{{ __('In use') }}</span>
                        @else
                            <a href="{{ url('/workspaces/switch/' . $workspace->id) }}" class="o-btn-primary text-xs">{{ __('Switch') }}</a>
                        @endif
                        @unless ($workspace->is_main)
                            <button type="button" wire:click="deleteWorkspace({{ $workspace->id }})"
                                wire:confirm="{{ __('Permanently delete the database ":name" and all its data? This cannot be undone.', ['name' => $workspace->name]) }}"
                                class="text-xs text-red-600 hover:underline">{{ __('delete') }}</button>
                        @endunless
                    </div>
                </li>
            @endforeach
        </ul>
    </div>

    <p class="mt-4 text-xs text-chrome-400">
        {{ __('Tip: switching changes the whole app to that database. Come back here to switch to Main.') }}
    </p>
</div>
