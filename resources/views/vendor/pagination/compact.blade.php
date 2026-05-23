@php
    /** @var \Illuminate\Pagination\AbstractPaginator $paginator */
    $paginator = $paginator ?? null;
@endphp

@if ($paginator !== null && $paginator->hasPages())
    @php
        // Page-number rail: always show 1 & 2 at the start and the last
        // page at the end, with an ellipsis bridging any gap. The current
        // page is also inserted if it isn't already in that core set, so
        // the user always sees where they are.
        //
        // Examples (last = 12):
        //   current=1  →  [1] 2 … 12
        //   current=2  →  1 [2] … 12
        //   current=5  →  1 2 … [5] … 12
        //   current=11 →  1 2 … [11] 12
        //   current=12 →  1 2 … [12]
        // For ≤4 pages we just render every page (no ellipsis ever needed).
        $current = (int) $paginator->currentPage();
        $last = (int) $paginator->lastPage();

        $items = [];
        if ($last <= 4) {
            for ($i = 1; $i <= $last; $i++) {
                $items[] = ['type' => 'page', 'n' => $i];
            }
        } else {
            $items[] = ['type' => 'page', 'n' => 1];
            $items[] = ['type' => 'page', 'n' => 2];

            // Drop the current page in the middle if it isn't already at
            // one of the edges (1, 2, or last) — keeps the "you are here"
            // dot visible even on deep pages.
            if (! in_array($current, [1, 2, $last - 1, $last], true)) {
                $items[] = ['type' => 'gap'];
                $items[] = ['type' => 'page', 'n' => $current];
            }

            // last-1 deserves a slot too when current is near the end, so
            // the rail reads "… 11 [12]" instead of jumping "… [12]".
            if ($current === $last - 1) {
                $items[] = ['type' => 'gap'];
                $items[] = ['type' => 'page', 'n' => $last - 1];
            }

            $items[] = ['type' => 'gap'];
            $items[] = ['type' => 'page', 'n' => $last];
        }
    @endphp

    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}"
         class="inline-flex items-stretch overflow-hidden rounded-md border border-chrome-300 bg-white text-xs font-medium text-chrome-700 shadow-sm">

        {{-- Previous --}}
        @if ($paginator->onFirstPage())
            <span aria-disabled="true" aria-label="{{ __('Previous') }}"
                  class="flex items-center px-2.5 py-1.5 text-chrome-300">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 0 1 0 1.06L9.06 10l3.73 3.71a.75.75 0 1 1-1.06 1.06l-4.25-4.24a.75.75 0 0 1 0-1.06l4.25-4.24a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd"/></svg>
            </span>
        @else
            <button type="button" wire:click="previousPage" wire:loading.attr="disabled"
                    aria-label="{{ __('Previous') }}"
                    class="flex items-center px-2.5 py-1.5 hover:bg-chrome-50">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 0 1 0 1.06L9.06 10l3.73 3.71a.75.75 0 1 1-1.06 1.06l-4.25-4.24a.75.75 0 0 1 0-1.06l4.25-4.24a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd"/></svg>
            </button>
        @endif

        {{-- Page numbers / gaps --}}
        @foreach ($items as $item)
            @if ($item['type'] === 'gap')
                <span aria-hidden="true"
                      class="flex select-none items-center border-s border-chrome-300 px-2 py-1.5 text-chrome-400">…</span>
            @elseif ($item['n'] === $current)
                <span aria-current="page"
                      class="flex min-w-[2rem] items-center justify-center border-s border-chrome-300 bg-primary-600 px-2 py-1.5 text-white">
                    {{ $item['n'] }}
                </span>
            @else
                <button type="button" wire:click="gotoPage({{ $item['n'] }})" wire:loading.attr="disabled"
                        class="flex min-w-[2rem] items-center justify-center border-s border-chrome-300 px-2 py-1.5 hover:bg-chrome-50">
                    {{ $item['n'] }}
                </button>
            @endif
        @endforeach

        {{-- Next --}}
        @if ($paginator->hasMorePages())
            <button type="button" wire:click="nextPage" wire:loading.attr="disabled"
                    aria-label="{{ __('Next') }}"
                    class="flex items-center border-s border-chrome-300 px-2.5 py-1.5 hover:bg-chrome-50">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 0-1.06L10.94 10 7.21 6.29a.75.75 0 1 1 1.06-1.06l4.25 4.24a.75.75 0 0 1 0 1.06l-4.25 4.24a.75.75 0 0 1-1.06 0Z" clip-rule="evenodd"/></svg>
            </button>
        @else
            <span aria-disabled="true" aria-label="{{ __('Next') }}"
                  class="flex items-center border-s border-chrome-300 px-2.5 py-1.5 text-chrome-300">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 0-1.06L10.94 10 7.21 6.29a.75.75 0 1 1 1.06-1.06l4.25 4.24a.75.75 0 0 1 0 1.06l-4.25 4.24a.75.75 0 0 1-1.06 0Z" clip-rule="evenodd"/></svg>
            </span>
        @endif
    </nav>
@endif
