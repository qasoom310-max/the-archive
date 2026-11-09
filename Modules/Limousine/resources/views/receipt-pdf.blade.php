{{-- The customer's copy of a payment — redesigned to match the invoice's
     reference-template style: a solid pastel band across the very top,
     a large plain "RECEIPT" title with date/booking #/receipt number as
     small label/value columns beside it, a two-column From / Received from,
     a plain-ruled payment-line table, and a right-aligned amount box.

     Table-based layout with inline styles because DomPDF supports neither
     flexbox nor grid. Same visual family as `invoice-pdf.blade.php` — this
     file carries its own complete <style> block rather than the shared
     `<x-pdf-styles />` component (a different, older visual language).

     The AMOUNT RECEIVED is still the headline — that is what the customer
     handed over and what they are keeping this for. What is still owed
     sits under it, stated plainly rather than left to be worked out. --}}
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ __('Receipt') }} {{ $reference }}</title>
    <style>
        @page { margin: 30px 34px 60px; }
        @include('limousine::partials.receipt-styles')
    </style>
</head>
<body>

@include('limousine::partials.receipt-body')

<x-document-footer />

</body>
</html>
