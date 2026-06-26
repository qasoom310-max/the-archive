@props([
    'title',
    'subtitle' => null,
    'icon' => null,          // key into the icon map below
    'accent' => 'primary',   // primary | indigo | emerald | sky | violet | amber | rose | chrome
])
{{--
    Shared page header for list / form pages across the apps: a soft-tinted
    icon chip + title + optional subtitle on the left, and an `actions` slot
    (buttons) on the right. Matches the dashboard's card design language.

    <x-page-header :title="__('Customers')" :subtitle="__('…')" icon="users" accent="primary">
        <x-slot:actions> … buttons … </x-slot:actions>
    </x-page-header>
--}}
@php
    $accentMap = [
        'primary' => 'bg-primary-100 text-primary-700 ring-primary-400/25',
        'indigo'  => 'bg-indigo-50 text-indigo-600 ring-indigo-100',
        'emerald' => 'bg-emerald-50 text-emerald-600 ring-emerald-100',
        'sky'     => 'bg-sky-50 text-sky-600 ring-sky-100',
        'violet'  => 'bg-violet-50 text-violet-600 ring-violet-100',
        'amber'   => 'bg-amber-50 text-amber-600 ring-amber-100',
        'rose'    => 'bg-rose-50 text-rose-600 ring-rose-100',
        'chrome'  => 'bg-chrome-100 text-chrome-600 ring-chrome-200',
    ];
    $chip = $accentMap[$accent] ?? $accentMap['primary'];

    $icons = [
        'users'    => '<path d="M10 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM6 8a2 2 0 1 1-4 0 2 2 0 0 1 4 0ZM1.49 15.326a.78.78 0 0 1-.358-.442 3 3 0 0 1 4.308-3.516 6.484 6.484 0 0 0-1.905 3.959c-.023.222-.014.442.025.654a4.97 4.97 0 0 1-2.07-.655ZM16.44 15.98a4.97 4.97 0 0 0 2.07-.654.78.78 0 0 0 .357-.442 3 3 0 0 0-4.308-3.517 6.484 6.484 0 0 1 1.907 3.96 2.32 2.32 0 0 1-.026.654ZM18 8a2 2 0 1 1-4 0 2 2 0 0 1 4 0ZM5.304 16.19a.844.844 0 0 1-.277-.71 5 5 0 0 1 9.947 0 .843.843 0 0 1-.277.71A6.975 6.975 0 0 1 10 18a6.974 6.974 0 0 1-4.696-1.81Z"/>',
        'user'     => '<path d="M10 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM3.465 14.493a1.23 1.23 0 0 0 .41 1.412A9.957 9.957 0 0 0 10 18c2.31 0 4.438-.784 6.131-2.1.43-.333.604-.903.408-1.41a7.002 7.002 0 0 0-13.074.003Z"/>',
        'car'      => '<path d="M3 9.5 4.2 6.6A2 2 0 0 1 6 5.5h8a2 2 0 0 1 1.8 1.1L17 9.5a2 2 0 0 1 1 1.7V13a1 1 0 0 1-1 1h-1a2 2 0 1 1-4 0H8a2 2 0 1 1-4 0H3a1 1 0 0 1-1-1v-1.8a2 2 0 0 1 1-1.7Z"/><circle cx="6.5" cy="14" r="1.5"/><circle cx="13.5" cy="14" r="1.5"/>',
        'doc'      => '<path fill-rule="evenodd" d="M4.5 2A1.5 1.5 0 0 0 3 3.5v13A1.5 1.5 0 0 0 4.5 18h11a1.5 1.5 0 0 0 1.5-1.5V7.621a1.5 1.5 0 0 0-.44-1.06l-3.62-3.622A1.5 1.5 0 0 0 11.378 2H4.5Zm2.25 8.5a.75.75 0 0 0 0 1.5h6.5a.75.75 0 0 0 0-1.5h-6.5Zm0 3a.75.75 0 0 0 0 1.5h6.5a.75.75 0 0 0 0-1.5h-6.5Z" clip-rule="evenodd"/>',
        'quote'    => '<path fill-rule="evenodd" d="M15.988 3.012A2.25 2.25 0 0 1 18 5.25v6.5A2.25 2.25 0 0 1 15.75 14H13.5v3.379a.75.75 0 0 1-1.28.53L8.69 14.5H4.25A2.25 2.25 0 0 1 2 12.25v-7A2.25 2.25 0 0 1 4.25 3h11.5q.12 0 .238.012ZM6 7a.75.75 0 0 0 0 1.5h8A.75.75 0 0 0 14 7H6Zm0 3a.75.75 0 0 0 0 1.5h5a.75.75 0 0 0 0-1.5H6Z" clip-rule="evenodd"/>',
        'receipt'  => '<path fill-rule="evenodd" d="M4.25 2A2.25 2.25 0 0 0 2 4.25v13.19a.75.75 0 0 0 1.117.654l1.616-.897 1.616.897a.75.75 0 0 0 .73 0l1.616-.897 1.617.897a.75.75 0 0 0 .73 0l1.616-.897 1.616.897a.75.75 0 0 0 .73 0l1.616-.897 1.617.897A.75.75 0 0 0 18 17.44V4.25A2.25 2.25 0 0 0 15.75 2H4.25ZM6 6.75A.75.75 0 0 1 6.75 6h6.5a.75.75 0 0 1 0 1.5h-6.5A.75.75 0 0 1 6 6.75Zm.75 2.5a.75.75 0 0 0 0 1.5h4.5a.75.75 0 0 0 0-1.5h-4.5Z" clip-rule="evenodd"/>',
        'swap'     => '<path fill-rule="evenodd" d="M2.24 6.8a.75.75 0 0 0 1.06-.04l1.95-2.1v8.59a.75.75 0 0 0 1.5 0V4.66l1.95 2.1a.75.75 0 1 0 1.1-1.02l-3.25-3.5a.75.75 0 0 0-1.1 0L2.2 5.74a.75.75 0 0 0 .04 1.06Zm8 6.4a.75.75 0 0 0-.04 1.06l3.25 3.5a.75.75 0 0 0 1.1 0l3.25-3.5a.75.75 0 1 0-1.1-1.02l-1.95 2.1V6.75a.75.75 0 0 0-1.5 0v8.59l-1.95-2.1a.75.75 0 0 0-1.06-.04Z" clip-rule="evenodd"/>',
        'wrench'   => '<path fill-rule="evenodd" d="M14.5 10a4.5 4.5 0 0 0 4.284-5.882c-.105-.324-.51-.391-.752-.15L15.34 6.66a.454.454 0 0 1-.493.11 3.01 3.01 0 0 1-1.618-1.616.455.455 0 0 1 .11-.494l2.694-2.692c.24-.241.174-.647-.15-.752a4.5 4.5 0 0 0-5.873 4.575c.055.873-.128 1.809-.8 2.368l-7.23 6.024a2.724 2.724 0 1 0 3.837 3.837l6.024-7.23c.56-.672 1.495-.855 2.368-.8.096.007.193.01.291.01Z" clip-rule="evenodd"/>',
        'chart'    => '<path d="M15.5 2A1.5 1.5 0 0 0 14 3.5v13a1.5 1.5 0 0 0 1.5 1.5h1a1.5 1.5 0 0 0 1.5-1.5v-13A1.5 1.5 0 0 0 16.5 2h-1ZM9.5 6A1.5 1.5 0 0 0 8 7.5v9A1.5 1.5 0 0 0 9.5 18h1a1.5 1.5 0 0 0 1.5-1.5v-9A1.5 1.5 0 0 0 10.5 6h-1ZM3.5 10A1.5 1.5 0 0 0 2 11.5v5A1.5 1.5 0 0 0 3.5 18h1A1.5 1.5 0 0 0 6 16.5v-5A1.5 1.5 0 0 0 4.5 10h-1Z"/>',
        'building' => '<path fill-rule="evenodd" d="M4 16.5v-13h-.25a.75.75 0 0 1 0-1.5h12.5a.75.75 0 0 1 0 1.5H16v13h.25a.75.75 0 0 1 0 1.5h-3.5a.75.75 0 0 1-.75-.75v-2.5a.75.75 0 0 0-.75-.75h-2.5a.75.75 0 0 0-.75.75v2.5a.75.75 0 0 1-.75.75h-3.5a.75.75 0 0 1 0-1.5H4Zm3-11a.75.75 0 0 1 .75-.75h.5a.75.75 0 0 1 0 1.5h-.5A.75.75 0 0 1 7 5.5Zm.75 2.25a.75.75 0 0 0 0 1.5h.5a.75.75 0 0 0 0-1.5h-.5ZM11 5.5a.75.75 0 0 1 .75-.75h.5a.75.75 0 0 1 0 1.5h-.5a.75.75 0 0 1-.75-.75Zm.75 2.25a.75.75 0 0 0 0 1.5h.5a.75.75 0 0 0 0-1.5h-.5Z" clip-rule="evenodd"/>',
        'calendar' => '<path fill-rule="evenodd" d="M5.75 2a.75.75 0 0 1 .75.75V4h7V2.75a.75.75 0 0 1 1.5 0V4h.25A2.75 2.75 0 0 1 18 6.75v8.5A2.75 2.75 0 0 1 15.25 18H4.75A2.75 2.75 0 0 1 2 15.25v-8.5A2.75 2.75 0 0 1 4.75 4H5V2.75A.75.75 0 0 1 5.75 2ZM3.5 8.5v6.75c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25V8.5h-13Z" clip-rule="evenodd"/>',
        'pin'      => '<path fill-rule="evenodd" d="m9.69 18.933.003.001C9.89 19.02 10 19 10 19s.11.02.308-.066l.002-.001.006-.003.018-.008a5.741 5.741 0 0 0 .281-.14c.186-.096.446-.24.757-.433.62-.384 1.445-.966 2.274-1.765C15.302 14.988 17 12.493 17 9A7 7 0 1 0 3 9c0 3.492 1.698 5.988 3.355 7.584a13.731 13.731 0 0 0 2.273 1.765 11.842 11.842 0 0 0 .976.544l.062.029.018.008.006.003ZM10 11.25a2.25 2.25 0 1 0 0-4.5 2.25 2.25 0 0 0 0 4.5Z" clip-rule="evenodd"/>',
        'wallet'   => '<path d="M2.273 5.625A4.483 4.483 0 0 1 5.25 4.5h9.5c1.141 0 2.183.425 2.977 1.125A3 3 0 0 0 14.75 3h-9.5a3 3 0 0 0-2.977 2.625ZM2.273 8.625A4.483 4.483 0 0 1 5.25 7.5h9.5c1.141 0 2.183.425 2.977 1.125A3 3 0 0 0 14.75 6h-9.5a3 3 0 0 0-2.977 2.625ZM5.25 9a3 3 0 0 0-3 3v3a3 3 0 0 0 3 3h9.5a3 3 0 0 0 3-3v-3a3 3 0 0 0-3-3H13a1 1 0 0 0-1 1 2 2 0 1 1-4 0 1 1 0 0 0-1-1H5.25Z"/>',
    ];
    $iconSvg = $icon !== null ? ($icons[$icon] ?? null) : null;
@endphp
<div {{ $attributes->merge(['class' => 'mb-5 flex flex-wrap items-center justify-between gap-3']) }}>
    <div class="flex items-center gap-3">
        @if ($iconSvg)
            <span class="flex size-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $chip }}">
                <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">{!! $iconSvg !!}</svg>
            </span>
        @endif
        <div>
            <h1 class="text-xl font-bold tracking-tight text-chrome-900">{{ $title }}</h1>
            @if ($subtitle)<p class="text-sm text-chrome-500">{{ $subtitle }}</p>@endif
        </div>
    </div>
    @isset($actions)
        <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
