{{--
    Custom 419 (CSRF token mismatch) page.

    Laravel's stock 419 template renders a friendly "Page Expired" message
    that the browser also accompanies with a confirm dialog on form re-POST.
    Neither is useful on a POS terminal mid-checkout — the cashier just
    needs the page back. We auto-reload via meta-refresh AND a JS redirect
    so it works whether scripts are enabled or not.

    The body is intentionally blank: at 0s the meta refresh fires, so any
    text would only flash for a millisecond before the reload.
--}}<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="0; url={{ url()->current() }}">
    <title>{{ __('Refreshing…') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('brand/openerp-mark.svg') }}">
    <script>window.location.replace({!! json_encode(url()->current()) !!});</script>
</head>
<body></body>
</html>
