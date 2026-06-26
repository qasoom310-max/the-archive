@props([
    'subject' => null,   // the Eloquent model whose audit trail to show
    'title' => null,
])
{{--
    A record's audit trail: who did what, in order (created → edited → approved …).
    Reads activity_logs by the polymorphic subject. Renders nothing when empty.

    <x-activity-trail :subject="$order" />
--}}
@php
    $entries = $subject === null
        ? collect()
        : \App\Models\ActivityLog::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
@endphp
@if ($entries->isNotEmpty())
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/[0.06]">
        <h2 class="mb-4 text-sm font-semibold text-chrome-800">{{ $title ?? __('Activity') }}</h2>
        <ol class="relative space-y-4 ps-4">
            <span class="absolute inset-y-1 start-[3px] w-px bg-chrome-200"></span>
            @foreach ($entries as $e)
                @php $tone = \App\Models\ActivityLog::COLORS[$e->action] ?? 'bg-chrome-100 text-chrome-600'; @endphp
                <li class="relative">
                    <span class="absolute -start-4 top-1.5 size-2 rounded-full bg-chrome-300 ring-2 ring-white"></span>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide {{ $tone }}">{{ __(\App\Models\ActivityLog::LABELS[$e->action] ?? ucfirst(str_replace('_', ' ', $e->action))) }}</span>
                        <span class="text-sm font-medium text-chrome-800">{{ $e->user_name }}</span>
                        <span class="text-xs text-chrome-400">{{ $e->created_at?->format('Y-m-d · H:i') }}</span>
                    </div>
                    @if ($e->description)
                        <p class="mt-0.5 text-xs text-chrome-500">{{ $e->description }}</p>
                    @endif
                </li>
            @endforeach
        </ol>
    </div>
@endif
