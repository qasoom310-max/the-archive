<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <div class="mb-6 flex items-center gap-3">
        <span class="flex size-11 items-center justify-center rounded-xl bg-primary-400 text-lg font-bold text-chrome-900">
            {{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($module->display_name, 0, 2)) }}
        </span>
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ $module->display_name }}</h1>
            <p class="text-sm text-chrome-500">{{ $module->summary ?? __('Application module') }} · v{{ $module->version }}</p>
        </div>
    </div>

    @include('partials.module-tiles', ['tiles' => $tiles])
</div>
