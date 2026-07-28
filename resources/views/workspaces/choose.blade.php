<x-layouts.guest :title="__('Choose a database')">
    <div class="mb-4 text-center">
        <h2 class="text-lg font-bold text-chrome-900">{{ __('Choose a database') }}</h2>
        <p class="mt-1 text-xs text-chrome-500">{{ __('Signed in as :name', ['name' => $userName]) }}</p>
    </div>

    <div class="space-y-2">
        @foreach ($workspaces as $workspace)
            <a href="{{ route('workspaces.enter', $workspace->id) }}"
                class="flex items-center justify-between gap-3 rounded-xl border px-4 py-3 text-start transition
                    {{ $currentId === $workspace->id
                        ? 'border-primary-400 bg-primary-50 ring-1 ring-primary-400'
                        : 'border-chrome-200 hover:border-primary-400 hover:bg-primary-50' }}">
                <span class="min-w-0">
                    <span class="block truncate font-semibold text-chrome-900">{{ $workspace->name }}</span>
                    @if ($currentId === $workspace->id)
                        <span class="block text-xs text-chrome-500">{{ __('Last used') }}</span>
                    @endif
                </span>
                <svg class="size-5 shrink-0 text-chrome-400 rtl:-scale-x-100" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/>
                </svg>
            </a>
        @endforeach
    </div>

    <form method="POST" action="{{ route('logout') }}" class="mt-5 text-center">
        @csrf
        <button type="submit" class="text-xs font-medium text-chrome-400 transition hover:text-chrome-700">
            {{ __('Sign out') }}
        </button>
    </form>
</x-layouts.guest>
