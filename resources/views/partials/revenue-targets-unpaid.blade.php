{{--
    "Still owed" for one window, under the figure it qualifies.

    Attainment counts collected money only, so a box can read 60% while the
    work for the rest of it is done and merely unpaid. This line is what tells
    the two apart. Nothing owed prints nothing - a row of zeroes would just
    make the real ones harder to spot.
--}}
@if ($unpaid > 0)
    <a href="{{ $href }}" wire:navigate
        class="mt-2 flex items-center justify-between rounded-lg bg-red-50 px-2.5 py-1.5 text-xs transition hover:bg-red-100">
        <span class="font-medium text-chrome-500">{{ __('Unpaid') }}</span>
        <span class="font-bold text-red-600">{{ \App\Erp\Views\ValueFormat::money($unpaid) }}</span>
    </a>
@endif
