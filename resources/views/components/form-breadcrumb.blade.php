@props([
    'parent',       // parent list label, e.g. __('Orders')
    'parentUrl',    // URL of the parent list
    'current',      // current record label, e.g. __('New order')
])
{{--
    Shared breadcrumb for form pages: "Parent › Current" with a chevron,
    matching the order form. Replaces the old plain "Parent / Current" line.

    <x-form-breadcrumb :parent="__('Orders')" :parent-url="url('/app/rental/order')"
                       :current="$isEditing ? $reference : __('New order')" />
--}}
<div {{ $attributes->merge(['class' => 'mb-5 flex items-center gap-2 text-sm text-chrome-400']) }}>
    <a href="{{ $parentUrl }}" wire:navigate class="font-medium hover:text-primary-700">{{ $parent }}</a>
    <svg class="size-3.5 rtl:rotate-180" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z" clip-rule="evenodd"/>
    </svg>
    <span class="font-semibold text-chrome-700">{{ $current }}</span>
</div>
