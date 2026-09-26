{{--
    Paid and still owed, for the work in one window.

    The figure above this line is WORK DONE. This line says what has happened
    to the money for it: how much the customers have paid, and how much they
    still owe - the owed figure being what the team chases this month.

    When outside vendors took a share (Rent A Car cars rented in), paid + owed
    comes to more than the work done, because the work done is only OUR part.
    The note says so rather than leaving two numbers that do not add up.

    Expects: $data (a box from RevenueTargets::progress()), $href.
--}}
<div class="mt-2 grid grid-cols-2 gap-2 text-xs">
    <div class="rounded-lg bg-emerald-50 px-2.5 py-1.5">
        <div class="font-medium text-emerald-700">{{ __('Paid') }}</div>
        <div class="font-bold text-emerald-800">{{ \App\Erp\Views\ValueFormat::money($data['paid']) }}</div>
    </div>
    <a href="{{ $href }}" wire:navigate
        class="rounded-lg px-2.5 py-1.5 transition {{ $data['unpaid'] > 0 ? 'bg-red-50 hover:bg-red-100' : 'bg-chrome-50' }}">
        <div class="font-medium {{ $data['unpaid'] > 0 ? 'text-red-700' : 'text-chrome-500' }}">{{ __('Still owed') }}</div>
        <div class="font-bold {{ $data['unpaid'] > 0 ? 'text-red-600' : 'text-chrome-500' }}">{{ \App\Erp\Views\ValueFormat::money($data['unpaid']) }}</div>
    </a>
</div>
@if ($data['vendors'] > 0)
    <p class="mt-1 text-[11px] leading-snug text-chrome-400">
        {{ __('Work done is after :amount paid to outside vendors.', ['amount' => \App\Erp\Views\ValueFormat::money($data['vendors'])]) }}
    </p>
@endif
