<div class="mx-auto max-w-4xl p-4 sm:p-6">
    <div class="mb-5">
        <h1 class="text-xl font-bold text-chrome-900">{{ __('Settings') }}</h1>
        <p class="text-sm text-chrome-500">{{ __('AI office — read-only keys that let your AI team read this ERP.') }}</p>
    </div>

    @include('partials.settings-nav', ['active' => 'ai_office'])

    @if ($newKey)
        <div class="mb-5 space-y-2 rounded-xl bg-amber-50 p-4 ring-1 ring-amber-200">
            <p class="text-sm font-semibold text-amber-900">{{ __('Copy this key now. It is shown only once.') }}</p>
            <input type="text" readonly value="{{ $newKey }}" onclick="this.select()" class="o-input font-mono text-xs" aria-label="{{ __('The new key') }}">
            <p class="text-xs text-amber-800">{{ __('Paste it into the AI office: Settings → Company data (ERP). Never send it by chat or email.') }}</p>
            <button type="button" wire:click="dismissKey" class="o-btn-secondary">{{ __('I have copied it') }}</button>
        </div>
    @endif

    <div class="mb-5 space-y-4 rounded-xl bg-white p-6 shadow-sm ring-1 ring-chrome-900/5">
        <div>
            <h2 class="text-sm font-semibold text-chrome-900">{{ __('Create a key') }}</h2>
            <p class="text-xs text-chrome-500">{{ __('The key can only read: it can never add, change, pay or delete anything. Names, dates, amounts and states only — no phone numbers, ID numbers or salaries per person.') }}</p>
        </div>
        <div class="grid items-start gap-2 sm:grid-cols-3">
            <label for="office-key-name" class="pt-2 text-sm font-medium text-chrome-800">{{ __('Name') }}</label>
            <div class="sm:col-span-2">
                <input id="office-key-name" type="text" wire:model="name" class="o-input" maxlength="120">
                @error('name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>
        <div class="grid items-start gap-2 sm:grid-cols-3">
            <span class="pt-2 text-sm font-medium text-chrome-800">{{ __('Companies it may read') }}</span>
            <div class="space-y-1 sm:col-span-2">
                @foreach ($workspaces as $workspace)
                    <label class="flex items-center gap-2 text-sm text-chrome-800">
                        <input type="checkbox" wire:model="workspaceIds" value="{{ $workspace->id }}" class="rounded border-chrome-300">
                        {{ $workspace->name }}
                    </label>
                @endforeach
                @error('workspaceIds') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>
        <div class="flex justify-end">
            <button type="button" wire:click="create" class="o-btn-primary">
                <span wire:loading.remove wire:target="create">{{ __('Create key') }}</span>
                <span wire:loading wire:target="create">{{ __('Creating…') }}</span>
            </button>
        </div>
    </div>

    <div class="rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
        <div class="border-b border-chrome-100 px-6 py-3">
            <h2 class="text-sm font-semibold text-chrome-900">{{ __('Keys') }}</h2>
        </div>
        @forelse ($keys as $key)
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-chrome-100 px-6 py-3 last:border-0">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-chrome-900">{{ $key->name }}</p>
                    <p class="text-xs text-chrome-500">
                        {{ collect($key->workspace_ids)->map(fn ($id) => $names[(int) $id] ?? '#'.$id)->implode(', ') }}
                        · {{ __('Last used') }}: {{ $key->last_used_at?->diffForHumans() ?? __('never') }}
                    </p>
                </div>
                @if ($key->isRevoked())
                    <span class="rounded-full bg-chrome-100 px-2 py-0.5 text-xs text-chrome-600">{{ __('Switched off') }}</span>
                @else
                    <button type="button" wire:click="revoke({{ $key->id }})" wire:confirm="{{ __('Switch this key off? The AI office stops reading the ERP at once.') }}" class="o-btn-secondary text-red-700">{{ __('Switch off') }}</button>
                @endif
            </div>
        @empty
            <p class="px-6 py-4 text-sm text-chrome-500">{{ __('No keys yet.') }}</p>
        @endforelse
    </div>
</div>
