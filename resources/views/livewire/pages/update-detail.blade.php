@php
    /** @var \App\Erp\Updates\UpdateEntry $entry */
    $card = 'rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5 sm:p-5';
    $heading = 'mb-2 text-sm font-semibold uppercase tracking-wide text-chrome-500';
@endphp

<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <a href="{{ route('updates') }}" wire:navigate class="mb-3 inline-block text-sm text-chrome-500 hover:text-chrome-800">
        <span class="rtl:hidden">←</span><span class="hidden rtl:inline">→</span> {{ __('All updates') }}
    </a>

    <div class="{{ $card }}">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <h1 class="text-xl font-bold text-chrome-900">{{ $entry->titleIn($locale) }}</h1>
            <span class="shrink-0 rounded-full bg-primary-50 px-2.5 py-0.5 text-xs font-medium text-primary-700">{{ $labels[$entry->app] }}</span>
        </div>
        <p class="mt-1 text-sm text-chrome-500">{{ $entry->date->format('d/m/Y') }}</p>

        <ul class="mt-3 list-disc space-y-1 ps-5 text-sm text-chrome-700">
            @foreach ($entry->pointsIn($locale) as $point)
                <li>{{ $point }}</li>
            @endforeach
        </ul>
    </div>

    @if ($entry->detailsIn($locale) !== [])
        <section class="{{ $card }} mt-4" data-update-details>
            <h2 class="{{ $heading }}">{{ __('What changed and why') }}</h2>
            <div class="space-y-3 text-sm leading-relaxed text-chrome-800">
                @foreach ($entry->detailsIn($locale) as $paragraph)
                    <p>{{ $paragraph }}</p>
                @endforeach
            </div>
        </section>
    @endif

    @if ($entry->images !== [])
        <section class="{{ $card }} mt-4" data-update-images>
            <h2 class="{{ $heading }}">{{ __('Before and after') }}</h2>
            <div class="space-y-6">
                @foreach ($entry->images as $image)
                    <figure>
                        <div class="grid gap-3 {{ $image['before'] !== null && $image['after'] !== null ? 'sm:grid-cols-2' : '' }}">
                            @foreach (['before' => __('Before'), 'after' => __('After')] as $side => $sideLabel)
                                @if ($image[$side] !== null)
                                    @php $src = route('updates.image', [$entry->key, $image[$side]]); @endphp
                                    <div>
                                        <div class="mb-1 inline-block rounded px-2 py-0.5 text-xs font-semibold {{ $side === 'before' ? 'bg-chrome-100 text-chrome-700' : 'bg-emerald-100 text-emerald-800' }}">{{ $sideLabel }}</div>
                                        <a href="{{ $src }}" target="_blank" rel="noopener" title="{{ __('Open the picture full size') }}">
                                            <img src="{{ $src }}" alt="{{ $sideLabel }}: {{ $image['caption'][$locale] ?? $image['caption']['en'] }}"
                                                 loading="lazy" class="w-full rounded-lg ring-1 ring-chrome-200">
                                        </a>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                        <figcaption class="mt-2 text-sm text-chrome-600">{{ $image['caption'][$locale] ?? $image['caption']['en'] }}</figcaption>
                    </figure>
                @endforeach
            </div>
        </section>
    @endif

    @if ($entry->guideIn($locale) !== [])
        <section class="{{ $card }} mt-4" data-update-guide>
            <h2 class="{{ $heading }}">{{ __('How to use it') }}</h2>
            <ol class="list-decimal space-y-2 ps-5 text-sm leading-relaxed text-chrome-800">
                @foreach ($entry->guideIn($locale) as $step)
                    <li>{{ $step }}</li>
                @endforeach
            </ol>
        </section>
    @endif
</div>
