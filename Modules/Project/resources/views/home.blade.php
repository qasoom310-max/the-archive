<div class="mx-auto max-w-6xl p-4 sm:p-6">
    <div class="mb-6 flex items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ __('Projects') }}</h1>
            <p class="text-sm text-chrome-500">{{ __('Pick a project to open its Kanban board.') }}</p>
        </div>
        @if ($canCreate)
            <a href="{{ url('/app/project/project/new') }}" wire:navigate
                class="o-btn-primary shrink-0 text-sm">{{ __('New project') }}</a>
        @endif
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @forelse ($projects as $project)
            <a href="{{ url('/app/project/' . $project->id . '/board') }}" wire:navigate
                class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5 transition hover:ring-primary-400">
                <div class="flex items-center gap-3">
                    <span class="size-3 shrink-0 rounded-full"
                        style="background: {{ $project->color ?: '#714b67' }}"></span>
                    <h2 class="truncate font-semibold text-chrome-800">{{ $project->name }}</h2>
                </div>
                <p class="mt-2 text-xs text-chrome-400">
                    {{ $project->tasks_count }} {{ __('tasks') }}
                </p>
            </a>
        @empty
            <p class="text-sm text-chrome-400">{{ __('No projects yet.') }}</p>
        @endforelse
    </div>
</div>
