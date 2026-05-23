@php
    /** @var \Illuminate\Pagination\AbstractPaginator $paginator */
    $paginator = $paginator ?? null;
@endphp

@if ($paginator !== null && $paginator->hasPages())
    {{-- Compact pagination: previous, "current / total", next. Avoids the
         long "1 2 3 4 5 …" rail — keeps the toolbar dense and stops the
         page-link list from running off-screen on data-heavy lists. --}}
    <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}"
         class="inline-flex items-stretch overflow-hidden rounded-md border border-chrome-300 bg-white text-xs font-medium text-chrome-700 shadow-sm">

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

        <span class="flex items-center border-x border-chrome-300 px-3 py-1.5 tabular-nums"
              aria-current="page">
            {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}
        </span>

        @if ($paginator->hasMorePages())
            <button type="button" wire:click="nextPage" wire:loading.attr="disabled"
                    aria-label="{{ __('Next') }}"
                    class="flex items-center px-2.5 py-1.5 hover:bg-chrome-50">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 0-1.06L10.94 10 7.21 6.29a.75.75 0 1 1 1.06-1.06l4.25 4.24a.75.75 0 0 1 0 1.06l-4.25 4.24a.75.75 0 0 1-1.06 0Z" clip-rule="evenodd"/></svg>
            </button>
        @else
            <span aria-disabled="true" aria-label="{{ __('Next') }}"
                  class="flex items-center px-2.5 py-1.5 text-chrome-300">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 0-1.06L10.94 10 7.21 6.29a.75.75 0 1 1 1.06-1.06l4.25 4.24a.75.75 0 0 1 0 1.06l-4.25 4.24a.75.75 0 0 1-1.06 0Z" clip-rule="evenodd"/></svg>
            </span>
        @endif
    </nav>
@endif
