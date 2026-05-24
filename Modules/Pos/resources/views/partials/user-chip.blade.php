{{--
    Cashier chip: initial-circle avatar + name (Odoo's POS 'C' avatar).
    Usage: @include('pos::partials.user-chip', ['user' => $session->user, 'sub' => '…'])
    `user` may be null (→ "Unassigned"); `sub` is optional.
--}}
@php
    $chipName = $user?->name ?? __('Unassigned');
    $chipInitial = \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($chipName, 0, 1)) ?: '?';
@endphp
<span class="inline-flex items-center gap-2">
    <span class="flex size-7 items-center justify-center rounded-full bg-primary-600 text-xs font-bold text-white"
        title="{{ $chipName }}">{{ $chipInitial }}</span>
    <span class="leading-tight">
        <span class="block text-sm font-medium text-chrome-800">{{ $chipName }}</span>
        @isset($sub)
            <span class="block text-[11px] text-chrome-400">{{ $sub }}</span>
        @endisset
    </span>
</span>
