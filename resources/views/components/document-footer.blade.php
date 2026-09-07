{{-- The band across the foot of every customer-facing document (the rental
     agreement, and the limousine quotation / invoice / receipt / statement /
     voucher / service order).

     It reads THIS database's own settings, so each workspace prints its own
     contact details — never hardcode an address or a phone number here, or
     every other business on the system gets Wanaan's.

     `position: fixed` is how DomPDF repeats a band on EVERY page: it is placed
     against the page box, so a host document must reserve room for it in its
     `@page` bottom margin — **60px**, which clears the band with room to
     spare (measured: band top sits ~7pt below the content floor). The style
     ships with the component so a host only widens that margin and drops
     `<x-document-footer />` in before `</body>`.

     `fixed` is that per-page behaviour, and it is what DomPDF wants. Pass
     `:fixed="false"` on a page a BROWSER prints (the list exports serve both a
     Print view and a PDF download): browsers disagree about whether a fixed
     element repeats per page, and on screen `bottom: -50px` would sit below
     the window entirely — so there it renders as an ordinary block at the end
     of the document instead, printing once after the last row. --}}
@props(['fixed' => true])

@php
    $companyName = trim((string) \App\Erp\Settings\Setting::get('company.name', ''));
    $companyAddress = trim((string) \App\Erp\Settings\Setting::get('company.address', ''));
    $companyEmail = trim((string) \App\Erp\Settings\Setting::get('company.email', ''));
    $companyWebsite = trim((string) \App\Erp\Settings\Setting::get('company.website', ''));

    // The hotline first, then any other numbers the office answers. The second
    // setting is a free list, so accept comma / semicolon / slash between them.
    $extraNumbers = preg_split('/[,;\/]+/', (string) \App\Erp\Settings\Setting::get('company.phone_alt', ''));
    $phones = array_values(array_filter(
        array_map(
            static fn (string $number): string => trim($number),
            array_merge(
                [(string) \App\Erp\Settings\Setting::get('company.phone', '')],
                is_array($extraNumbers) ? $extraNumbers : [],
            ),
        ),
        static fn (string $number): bool => $number !== '',
    ));

    $contact = array_values(array_filter([$companyEmail, $companyWebsite], static fn (string $v): bool => $v !== ''));

    // The registration numbers a customer's accounts department needs off a
    // receipt to claim the VAT and to file the invoice against a real trader.
    $vatNumber = trim((string) \App\Erp\Settings\Setting::get('company.vat_number', ''));
    $crNumber = trim((string) \App\Erp\Settings\Setting::get('company.cr_number', ''));

    $registrations = [];
    if ($vatNumber !== '') {
        $registrations[] = __('VAT No.') . ': ' . $vatNumber;
    }
    if ($crNumber !== '') {
        $registrations[] = __('CR No.') . ': ' . $crNumber;
    }
@endphp

{{-- A company NAME alone is not worth a grey bar — every document already
     prints it in the header, and an unconfigured database still carries the
     "OpenERP" default, which would put a stranger's name on someone's
     invoice. The band is for contact details, so it prints only when this
     database has some. --}}
@if ($phones !== [] || $companyAddress !== '' || $contact !== [] || $registrations !== [])
    <style>
        .doc-footer {
            font-size: 8.5px;
            line-height: 1.45;
            color: #1f2937;
        }
        .doc-footer-fixed {
            position: fixed;
            left: 0;
            right: 0;
            bottom: -50px;
        }
        .doc-footer-flow {
            margin-top: 18px;
        }
        .doc-footer table {
            width: 100%;
            border-collapse: collapse;
            background: #d9d9d9;
        }
        .doc-footer td {
            padding: 5px 9px;
            vertical-align: middle;
            border: 0;
        }
        .doc-footer .doc-footer-name {
            font-weight: bold;
            letter-spacing: .3px;
        }
        .doc-footer .doc-footer-end {
            text-align: right;
        }
        .doc-footer .doc-footer-soft {
            color: #4b5563;
        }
    </style>

    <div class="doc-footer {{ $fixed ? 'doc-footer-fixed' : 'doc-footer-flow' }}">
        <table>
            <tr>
                <td>
                    @if ($companyName !== '')
                        <span class="doc-footer-name">{{ $companyName }}</span>@if ($phones !== [])<br>@endif
                    @endif
                    @if ($phones !== [])
                        {{ __('Hotline') }}: {{ implode('  ·  ', $phones) }}
                    @endif
                    @if ($registrations !== [])
                        @if ($companyName !== '' || $phones !== [])<br>@endif
                        <span class="doc-footer-soft">{{ implode('  ·  ', $registrations) }}</span>
                    @endif
                </td>
                <td class="doc-footer-end">
                    @if ($companyAddress !== ''){{ $companyAddress }}@endif
                    @if ($companyAddress !== '' && $contact !== [])<br>@endif
                    @if ($contact !== [])<span class="doc-footer-soft">{{ implode('  ·  ', $contact) }}</span>@endif
                </td>
            </tr>
        </table>
    </div>
@endif
