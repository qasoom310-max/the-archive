@php
    /** @var array<string, list<\App\Erp\Updates\UpdateEntry>> $days */
    $chip = 'rounded-full px-3 py-1 text-sm font-medium transition-colors';
    $chipOn = 'bg-primary-600 text-white';
    $chipOff = 'bg-white text-chrome-700 ring-1 ring-chrome-200 hover:bg-chrome-50';
@endphp

{{-- Opening this page is what "reading the news" means: remember the newest
     entry on this device so the top-bar dot goes out. --}}
<div class="mx-auto max-w-3xl p-4 sm:p-6"
     @if ($latestKey !== null)
         x-data x-init="try { localStorage.setItem('erp-updates-seen', @js($latestKey)) } catch (e) {}; window.dispatchEvent(new CustomEvent('erp-updates-seen'))"
     @endif>
    <div class="mb-4">
        <h1 class="text-xl font-bold text-chrome-900">{{ __("What's new") }}</h1>
        <p class="text-sm text-chrome-500">{{ __('Every change made to the system, newest first.') }}</p>
    </div>

    <div class="mb-4 flex flex-col gap-3">
        <input type="search" wire:model.live.debounce.300ms="search" data-updates-search
               placeholder="{{ __('Search the updates…') }}"
               class="o-input w-full">

        @if (count($apps) > 1)
            <div class="flex flex-wrap gap-2" data-updates-apps>
                <button type="button" wire:click="setApp('')"
                        class="{{ $chip }} {{ $activeApp === '' ? $chipOn : $chipOff }}">{{ __('All') }}</button>
                @foreach ($apps as $key)
                    <button type="button" wire:click="setApp('{{ $key }}')"
                            class="{{ $chip }} {{ $activeApp === $key ? $chipOn : $chipOff }}">{{ $labels[$key] }}</button>
                @endforeach
            </div>
        @endif
    </div>

    @forelse ($days as $day => $entries)
        <section class="mb-6" wire:key="day-{{ $day }}">
            <h2 class="mb-2 text-xs font-semibold uppercase tracking-wide text-chrome-500">
                {{ \Illuminate\Support\Carbon::parse($day)->format('d/m/Y') }}
            </h2>

            <div class="space-y-3">
                @foreach ($entries as $entry)
                    <article wire:key="update-{{ $entry->key }}" data-update="{{ $entry->key }}"
                             class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <h3 class="text-base font-semibold text-chrome-900">{{ $entry->titleIn($locale) }}</h3>
                            <span class="shrink-0 rounded-full bg-primary-50 px-2.5 py-0.5 text-xs font-medium text-primary-700">{{ $labels[$entry->app] }}</span>
                        </div>
                        <ul class="mt-2 list-disc space-y-1 ps-5 text-sm text-chrome-700">
                            @foreach ($entry->pointsIn($locale) as $point)
                                <li>{{ $point }}</li>
                            @endforeach
                        </ul>
                        @if ($entry->hasFullStory())
                            <a href="{{ route('updates.show', $entry->key) }}" wire:navigate data-update-more
                               class="mt-3 inline-flex items-center gap-1 text-sm font-medium text-primary-700 hover:underline">
                                {{ __('Full explanation and guide') }}
                                @if ($entry->images !== [])
                                    <span class="text-chrome-400">· {{ __('with pictures') }}</span>
                                @endif
                            </a>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>
    @empty
        <div class="rounded-xl bg-white p-8 text-center text-sm text-chrome-500 shadow-sm ring-1 ring-chrome-900/5">
            {{ __('No updates match.') }}
        </div>
    @endforelse

    @if ($remaining > 0)
        <button type="button" wire:click="showMore" class="o-btn-ghost w-full justify-center">
            {{ __('Show older updates (:count more)', ['count' => $remaining]) }}
        </button>
    @endif
</div>
