<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <div class="mb-4 flex items-center gap-2 text-sm text-chrome-500">
        <a href="{{ url('/app/project') }}" wire:navigate class="hover:text-primary-700">{{ __('Projects') }}</a>
        <span>/</span>
        <span class="font-medium text-chrome-700">{{ $project?->name ?? __('New project') }}</span>
    </div>

    <livewire:views.form-view
        :model="\Modules\Project\Models\Project::class"
        model-key="project.project"
        :record-id="$project?->id"
        :title="$project ? __('Edit project') : __('New project')"
        :key="'project-form-' . ($project?->id ?? 'new')" />

    @if ($project)
        <div class="mx-auto mt-4 max-w-3xl">
            <a href="{{ url('/app/project/' . $project->id . '/board') }}" wire:navigate
                class="o-btn-primary">{{ __('Open board') }}</a>
        </div>
    @endif
</div>
