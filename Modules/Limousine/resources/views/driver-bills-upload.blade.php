{{-- The driver's own bill upload, from the link sent with his trip. Standalone
     (no app chrome, no login): a driver on a phone, often inside WhatsApp. --}}
<!DOCTYPE html>
@php $isRtl = in_array(app()->getLocale(), ['ar'], true); @endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('Send your bills') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-full bg-chrome-100 font-sans">
<div class="mx-auto max-w-md p-4">
    @if ($expired)
        <div class="rounded-2xl bg-white p-6 text-center shadow-sm ring-1 ring-chrome-900/5" data-link-expired>
            <h1 class="text-lg font-bold text-chrome-900">{{ __('This link has expired') }}</h1>
            <p class="mt-2 text-sm text-chrome-600">{{ __('Links for sending bills work for 48 hours. Ask the office for a new one.') }}</p>
        </div>
    @else
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5" data-driver-upload>
            <h1 class="text-lg font-bold text-chrome-900">{{ __('Send your bills') }}</h1>
            <p class="mt-1 text-sm text-chrome-600">{{ __('Hello :name. Take a photo of each bill you paid for this trip. The office checks them.', ['name' => $driverName]) }}</p>
            <p class="mt-3 rounded-lg bg-chrome-50 px-3 py-2 text-sm text-chrome-700" dir="ltr">
                {{ __('Trip') }} <span class="font-semibold">{{ $trip->reference }}</span>
                @if ($trip->start_at) · {{ $trip->start_at->format('j M Y') }} @endif
            </p>

            {{-- Both languages at once, whatever the page language: every driver must read it. --}}
            <div class="mt-4 space-y-2 rounded-xl border-2 border-amber-400 bg-amber-50 p-4 text-sm font-semibold text-amber-900" role="note" data-original-bills-note>
                <p dir="ltr">⚠️ {{ \Modules\Limousine\Support\DriverBillLink::NOTE_EN }}</p>
                <p dir="rtl" lang="ar">⚠️ {{ \Modules\Limousine\Support\DriverBillLink::NOTE_AR }}</p>
            </div>

            <div class="mt-5 flex flex-col gap-3">
                <label class="o-btn-primary w-full cursor-pointer justify-center py-3" data-pick>
                    {{ __('Take a photo') }}
                    <input type="file" accept="image/jpeg,image/png,image/webp" capture="environment" class="hidden" data-file>
                </label>
                <label class="o-btn-ghost w-full cursor-pointer justify-center py-3 ring-1 ring-chrome-200" data-pick>
                    {{ __('Choose pictures') }}
                    <input type="file" accept="image/jpeg,image/png,image/webp" multiple class="hidden" data-file>
                </label>
            </div>

            <p id="busy" hidden class="mt-4 text-sm font-medium text-chrome-600">{{ __('Sending… keep this page open.') }}</p>
            <p id="error" hidden class="mt-4 text-sm font-medium text-red-600" role="alert"></p>
            <p id="done" hidden class="mt-4 rounded-lg bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800" role="status">
                {{ __('Sent. Take the next one, or close this page when you are done.') }}
            </p>
            <p class="mt-4 text-xs text-chrome-500"><span id="sent">{{ $sent }}</span> {{ __('bills sent with this link') }}</p>
        </div>
        <script>
            (function () {
                var url = @js($uploadUrl), max = {{ $max }}, sent = {{ $sent }};
                var full = @js(__('This link has taken all the bills it can. Send the rest to the office.'));
                var failed = @js(__('Upload failed.'));
                var $ = function (id) { return document.getElementById(id); };
                var picks = document.querySelectorAll('[data-pick]');
                function busy(on) { $('busy').hidden = !on; picks.forEach(function (p) { p.style.opacity = on ? '.5' : ''; p.style.pointerEvents = on ? 'none' : ''; }); }
                function fail(msg) { $('error').textContent = msg; $('error').hidden = false; }
                document.querySelectorAll('[data-file]').forEach(function (input) {
                    input.addEventListener('change', async function () {
                        var files = Array.prototype.slice.call(input.files || []);
                        if (!files.length) return;
                        $('error').hidden = true; $('done').hidden = true; busy(true);
                        for (var i = 0; i < files.length; i++) {
                            if (sent >= max) { fail(full); break; }
                            var data = new FormData(); data.append('file', files[i]);
                            try {
                                var r = await fetch(url, { method: 'POST', body: data, credentials: 'same-origin',
                                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' } });
                                var j = await r.json().catch(function () { return {}; });
                                if (!r.ok) { fail((j.errors && Object.values(j.errors)[0][0]) || j.message || failed); break; }
                                sent = j.sent; $('sent').textContent = sent; $('done').hidden = false;
                            } catch (e) { fail(failed); break; }
                        }
                        busy(false); input.value = '';
                    });
                });
            })();
        </script>
    @endif
</div>
</body>
</html>
