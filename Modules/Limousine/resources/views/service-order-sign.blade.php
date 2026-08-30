{{-- Customer-facing signing page. Standalone (no app chrome, no login) because
     the person opening it is a customer with an emailed link, on a phone, in a
     car park. Everything is inline and self-contained for that reason. --}}
<!DOCTYPE html>
@php $isRtl = in_array(app()->getLocale(), ['ar'], true); @endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('Service Order') }} {{ $confirmationNo }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-full bg-chrome-100 font-sans">
<div class="mx-auto max-w-lg p-4 sm:p-6">

    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h1 class="text-lg font-bold text-chrome-900">{{ __('Service Order') }}</h1>
                <p class="text-xs text-chrome-500">{{ __('Confirmation No.') }} {{ $confirmationNo }}</p>
            </div>
            @if ($leg->isSigned())
                <span class="shrink-0 rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700">✓ {{ __('Signed') }}</span>
            @endif
        </div>

        @if (session('service_order_status'))
            <div class="mt-4 rounded-lg bg-emerald-50 px-4 py-2.5 text-sm font-medium text-emerald-800 ring-1 ring-emerald-200">
                {{ session('service_order_status') }}
            </div>
        @endif

        {{-- The trip, so the customer confirms what they are signing for. --}}
        <dl class="mt-4 divide-y divide-chrome-100 text-sm">
            @foreach ([
                [__('Customer'), $customerName],
                [__('Service date'), trim($serviceDate . ' ' . $serviceTime)],
                [__('Pick up'), $pickup],
                [__('Drop off'), $dropoff],
                [__('Vehicle'), $vehicle],
                [__("Driver's Name"), $driverName],
            ] as [$label, $value])
                @if ($value !== '')
                    <div class="flex justify-between gap-4 py-2">
                        <dt class="shrink-0 text-chrome-500">{{ $label }}</dt>
                        <dd class="text-end font-medium text-chrome-800">{{ $value }}</dd>
                    </div>
                @endif
            @endforeach
            <div class="flex justify-between gap-4 py-2">
                <dt class="shrink-0 text-chrome-500">{{ __('Amount') }}</dt>
                <dd class="text-end font-bold text-chrome-900">{{ number_format($amount, 3) }} {{ __('BHD') }}</dd>
            </div>
        </dl>
    </div>

    @if ($leg->isSigned())
        {{-- Already signed: show the proof rather than another pad, so an
             existing signature can never be quietly replaced. --}}
        <div class="mt-4 rounded-2xl bg-white p-5 text-center shadow-sm ring-1 ring-chrome-900/5">
            <p class="text-sm font-medium text-chrome-700">{{ __('Signed by') }} {{ $leg->signed_name }}</p>
            <p class="text-xs text-chrome-400">{{ $leg->signed_at?->isoFormat('DD-MMM-YYYY hh:mm A') }}</p>
            @if ($signatureUrl)
                <img src="{{ $signatureUrl }}" alt="{{ __('Customer Signature') }}"
                     class="mx-auto mt-3 max-h-24 rounded border border-chrome-200 bg-white p-2">
            @endif
            <a href="{{ url()->current() }}/pdf" target="_blank" rel="noopener"
               class="mt-4 inline-block text-sm font-semibold text-primary-700 hover:underline">{{ __('Download a copy (PDF)') }}</a>
        </div>
    @else
        <form method="POST" action="{{ url()->full() }}"
              class="mt-4 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5"
              onsubmit="return signaturePad.commit()">
            @csrf

            <label class="block text-sm font-medium text-chrome-800">{{ __('Your name') }}</label>
            <input type="text" name="signed_name" value="{{ old('signed_name', $customerName) }}" required
                   class="mt-1 w-full rounded-lg border border-chrome-300 px-3 py-2 text-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20">
            @error('signed_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

            <label class="mt-4 block text-sm font-medium text-chrome-800">{{ __('Sign below') }}</label>
            <p class="text-xs text-chrome-500">{{ __('Draw your signature with your finger or mouse.') }}</p>

            {{-- Plain canvas: a signature is a few strokes, so it needs no
                 library — and this page must load fast on a phone. --}}
            <div class="mt-2 rounded-lg border-2 border-dashed border-chrome-300 bg-chrome-50">
                <canvas id="sig-pad" class="h-44 w-full touch-none rounded-lg"></canvas>
            </div>
            <input type="hidden" name="signature" id="sig-data">
            @error('signature') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

            <div class="mt-2 flex items-center justify-between">
                <button type="button" onclick="signaturePad.clear()"
                        class="text-xs font-medium text-chrome-500 hover:text-chrome-800">{{ __('Clear') }}</button>
                <span id="sig-hint" class="text-xs text-chrome-400">{{ __('Not signed yet') }}</span>
            </div>

            <button type="submit"
                    class="mt-4 w-full rounded-lg bg-primary-400 px-4 py-3 text-base font-bold text-chrome-900 transition hover:brightness-95">
                {{ __('Confirm & submit') }}
            </button>
            <p class="mt-2 text-center text-[11px] text-chrome-400">
                {{ __('By signing you confirm the driver arrived and the service was provided.') }}
            </p>
        </form>
    @endif

    <p class="mt-4 text-center text-[11px] text-chrome-400">{{ config('app.name') }}</p>
</div>

@if (! $leg->isSigned())
<script>
    // Minimal signature pad: track pointer strokes on a backing store sized to
    // the device pixel ratio, so the saved PNG is crisp on a phone rather than
    // the blurry upscale a CSS-sized canvas would give.
    const signaturePad = (function () {
        const canvas = document.getElementById('sig-pad');
        if (!canvas) return { clear() {}, commit() { return true; } };

        const ctx = canvas.getContext('2d');
        const hint = document.getElementById('sig-hint');
        const field = document.getElementById('sig-data');
        let drawing = false, dirty = false;

        function resize() {
            // Preserve what is already drawn across an orientation change.
            const prev = dirty ? canvas.toDataURL('image/png') : null;
            const ratio = window.devicePixelRatio || 1;
            canvas.width = canvas.offsetWidth * ratio;
            canvas.height = canvas.offsetHeight * ratio;
            ctx.scale(ratio, ratio);
            ctx.lineWidth = 2.2;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            ctx.strokeStyle = '#111827';
            if (prev) {
                const img = new Image();
                img.onload = () => ctx.drawImage(img, 0, 0, canvas.offsetWidth, canvas.offsetHeight);
                img.src = prev;
            }
        }

        function pos(e) {
            const r = canvas.getBoundingClientRect();
            const p = e.touches ? e.touches[0] : e;
            return { x: p.clientX - r.left, y: p.clientY - r.top };
        }

        function start(e) { e.preventDefault(); drawing = true; const p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); }
        function move(e) {
            if (!drawing) return;
            e.preventDefault();
            const p = pos(e);
            ctx.lineTo(p.x, p.y); ctx.stroke();
            if (!dirty) { dirty = true; hint.textContent = @js(__('Signed')); hint.className = 'text-xs font-medium text-emerald-600'; }
        }
        function end() { drawing = false; }

        canvas.addEventListener('mousedown', start);
        canvas.addEventListener('mousemove', move);
        window.addEventListener('mouseup', end);
        canvas.addEventListener('touchstart', start, { passive: false });
        canvas.addEventListener('touchmove', move, { passive: false });
        canvas.addEventListener('touchend', end);
        window.addEventListener('resize', resize);
        resize();

        return {
            clear() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                dirty = false;
                field.value = '';
                hint.textContent = @js(__('Not signed yet'));
                hint.className = 'text-xs text-chrome-400';
            },
            // Block submit on an empty pad — an blank signature is not proof.
            commit() {
                if (!dirty) {
                    hint.textContent = @js(__('Please sign before submitting.'));
                    hint.className = 'text-xs font-medium text-red-600';
                    return false;
                }
                field.value = canvas.toDataURL('image/png');
                return true;
            },
        };
    })();
</script>
@endif
</body>
</html>
