{{-- Several ticked receipts downloaded as ONE PDF — the toolbar's "PDF"
     button on the receipts list, when rows are ticked. Each receipt keeps its
     own full page in the exact design of `receipt-pdf.blade.php` (they share
     `partials/receipt-body` + `partials/receipt-styles`). The footer is
     declared ONCE: `position: fixed` repeats it on every page DomPDF
     paginates, the manual page breaks below included. --}}
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Receipts') }}</title>
    <style>
        @page { margin: 30px 34px 60px; }
        @include('limousine::partials.receipt-styles')
        .receipt-page { page-break-before: always; }
    </style>
</head>
<body>

@foreach ($receipts as $receiptData)
    <div @if (! $loop->first) class="receipt-page" @endif>
        @include('limousine::partials.receipt-body', $receiptData)
    </div>
@endforeach

<x-document-footer />

</body>
</html>
