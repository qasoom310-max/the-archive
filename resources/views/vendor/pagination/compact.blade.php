@php
    /** @var \Illuminate\Pagination\AbstractPaginator $paginator */
    $paginator = $paginator ?? null;
@endphp

@if ($paginator !== null && $paginator->hasPages())
    @php
        // Sliding-window pagination:
        //   - Always show first 2 pages (anchor the start)
        //   - Always show last page (anchor the end)
        //   - Always show current page ± 1 (the "window buffer")
        //   - Insert "…" between any non-consecutive entries
        // So the current page is NEVER hidden behind an ellipsis.
        //
        // Examples (last = 10):
        //   current=1   →  [1] 2 … 10
        //   current=3   →  1 2 [3] 4 … 10
        //   current=5   →  1 2 … 4 [5] 6 … 10
        //   current=9   →  1 2 … 8 [9] 10
        //   current=10  →  1 2 … 9 [10]
        // For small page counts the windows merge and you just get the
        // full run (e.g. last=5, current=3 → 1 2 [3] 4 5, no ellipsis).
        $current = (int) $paginator->currentPage();
        $last = (int) $paginator->lastPage();

        $pages = [1];
        if ($last >= 2) {
            $pages[] = 2;
        }
        foreach ([-1, 0, 1] as $delta) {
            $candidate = $current + $delta;
            if ($candidate >= 1 && $candidate <= $last) {
                $pages[] = $candidate;
            }
        }
        $pages[] = $last;

        $pages = array_values(array_unique($pages));
        sort($pages);

        // Walk the de-duped sorted list and drop an "…" between any
        // non-consecutive pair (e.g. between 2 and 4 → insert gap).
        $items = [];
        $previous = null;
        foreach ($pages as $page) {
            if ($previous !== null && $page - $previous > 1) {
                $items[] = ['type' => 'gap'];
            }
            $items[] = ['type' => 'page', 'n' => $page];
            $previous = $page;
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

        @foreach ($items as $item)
            @if ($item['type'] === 'gap')
                <span aria-hidden="true"
                      class="flex select-none items-center border-s border-chrome-300 px-2 py-1.5 text-chrome-400">…</span>
            @elseif ($item['n'] === $current)
                <span aria-current="page"
                      class="flex min-w-[2rem] items-center justify-center border-s border-chrome-300 bg-primary-400 px-2 py-1.5 text-chrome-900">
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
