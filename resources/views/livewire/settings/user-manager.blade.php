<div class="space-y-6">
    <div>
        <h2 class="text-sm font-semibold text-chrome-800">{{ __('Add user') }}</h2>
        <p class="mt-1 text-sm text-chrome-500">
            {{ __('Create a staff account and choose which apps and databases they can see (view only).') }}
        </p>
    </div>

    @if (session('user_created'))
        <p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700 ring-1 ring-emerald-200">
            {{ session('user_created') }}
        </p>
    @endif

    <form wire:submit="createUser" class="space-y-5">
        {{-- Credentials --}}
        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Username') }} <span class="text-red-500">*</span></label>
                <input type="text" wire:model="name" autocomplete="off" class="o-input">
                @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Email') }} <span class="text-red-500">*</span></label>
                <input type="email" wire:model="email" autocomplete="off" class="o-input">
                @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Password') }} <span class="text-red-500">*</span></label>
                <input type="password" wire:model="password" autocomplete="new-password" class="o-input">
                @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- App access --}}
        <div>
            <label class="mb-2 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Apps this user can access') }}</label>
            @if ($appModules->isEmpty())
                <p class="text-sm text-chrome-400">{{ __('No apps installed yet.') }}</p>
            @else
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($appModules as $app)
                        @php
                            $moduleKey = 'module.' . $app->name;
                            $label = __($moduleKey);
                            if ($label === $moduleKey) { $label = $app->display_name; }
                        @endphp
                        <label wire:key="app-{{ $app->id }}"
                            class="flex cursor-pointer items-center gap-2 rounded-lg border border-chrome-200 px-3 py-2 text-sm hover:bg-chrome-50">
                            <input type="checkbox" wire:model="apps" value="{{ $app->name }}"
                                class="rounded border-chrome-300 text-primary-600 focus:ring-primary-500">
                            <span class="text-chrome-700">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Database access --}}
        <div>
            <label class="mb-2 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Databases this user can access') }}</label>
            <p class="mb-2 text-xs text-chrome-400">{{ __('The Main database is always included. Pick any additional databases to create this account in.') }}</p>
            @if ($workspaceList->isEmpty())
                <p class="text-sm text-chrome-400">{{ __('No additional databases. Create one under My database.') }}</p>
            @else
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($workspaceList as $workspace)
                        <label wire:key="ws-{{ $workspace->id }}"
                            class="flex cursor-pointer items-center gap-2 rounded-lg border border-chrome-200 px-3 py-2 text-sm hover:bg-chrome-50">
                            <input type="checkbox" wire:model="workspaces" value="{{ $workspace->id }}"
                                class="rounded border-chrome-300 text-primary-600 focus:ring-primary-500">
                            <span class="text-chrome-700">{{ $workspace->name }}</span>
                        </label>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="flex justify-end border-t border-chrome-100 pt-4">
            <button type="submit" class="o-btn-primary" wire:loading.attr="disabled" wire:target="createUser">
                <span wire:loading.remove wire:target="createUser">{{ __('Create user') }}</span>
                <span wire:loading wire:target="createUser">{{ __('Creating…') }}</span>
            </button>
        </div>
    </form>

    {{-- Existing staff users --}}
    <div class="border-t border-chrome-100 pt-5">
        <h3 class="mb-2 text-sm font-semibold text-chrome-800">{{ __('Staff users') }}</h3>
        <ul class="divide-y divide-chrome-100 rounded-lg border border-chrome-100">
            @forelse ($users as $user)
                <li wire:key="user-{{ $user->id }}" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                    <span class="min-w-0 truncate">
                        <span class="font-medium text-chrome-700">{{ $user->name }}</span>
                        <span class="text-chrome-400">— {{ $user->email }}</span>
                    </span>
                    <button type="button" wire:click="deleteUser({{ $user->id }})"
                        wire:confirm="{{ __('Delete :name?', ['name' => $user->name]) }}"
                        class="shrink-0 text-xs text-red-600 hover:underline">{{ __('remove') }}</button>
                </li>
            @empty
                <li class="px-3 py-4 text-center text-sm text-chrome-400">{{ __('No staff users yet.') }}</li>
            @endforelse
        </ul>
    </div>
</div>
