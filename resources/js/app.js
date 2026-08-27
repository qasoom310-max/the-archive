import './bootstrap';

/**
 * Bring the first field that failed validation into view and focus it.
 *
 * Livewire renders the inline `@error` message but leaves the viewport put,
 * so on a tall form a required field below the fold makes "Save" look like it
 * did nothing. A component's `ScrollsToFirstError::validateFocusing()` dispatches
 * `scroll-to-error` with the failing field's name; the layout forwards it here.
 *
 * The field name matches a control's `wire:model` (any modifier) value —
 * including nested keys like `legs.0.start_at`. We scan real form controls so
 * the modifier list never has to be enumerated.
 */
window.scrollToFieldError = function (field) {
    if (!field) return;

    let target = null;
    for (const el of document.querySelectorAll('input, select, textarea')) {
        for (const attr of el.attributes) {
            if ((attr.name === 'wire:model' || attr.name.startsWith('wire:model.')) && attr.value === field) {
                target = el;
                break;
            }
        }
        if (target) break;
    }
    if (!target) return;

    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    target.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center' });
    // Focus once the smooth scroll has settled; preventScroll so focus itself
    // doesn't yank the viewport a second time. Guard focus() for odd controls.
    window.setTimeout(() => {
        try { target.focus({ preventScroll: true }); } catch (e) { /* not focusable */ }
    }, reduce ? 0 : 300);
};

/**
 * Engine ListView column-picker drag-to-reorder. Wired via the inline
 * `x-data="listColumnPicker"` on the picker's <ul> in
 * resources/views/livewire/views/list-view.blade.php.
 *
 * Plain HTML5 drag-and-drop — avoids pulling in Sortable.js (one less
 * npm dep to install on Hostinger, where heavy packages have hit AV
 * file-lock issues before).
 *
 * The picker <ul> sits inside a `wire:ignore` container so the drag
 * listeners stay live across `toggleColumn` round-trips. The container
 * is wrapped in `x-show="open"` but x-show uses `display:none` — the
 * element stays in the DOM, so init runs once on mount and our
 * listeners are ready by the time the user opens the dropdown.
 *
 * On drop, posts the new field-order array to Livewire's
 * `reorderColumns` action which persists it per (user, model) in
 * `user_view_preferences`.
 *
 * IMPORTANT: capture `wire` via closure — do NOT store it on `this`.
 * Alpine wraps `this`-stored values in its own reactivity proxy, which
 * intercepts `.call(...)` on the underlying $wire proxy and ends up
 * invoking Vue's internal `__v_raw` accessor as a Livewire method name
 * (→ MethodNotFoundException, 500). Closure capture keeps the $wire
 * reference unwrapped.
 */
document.addEventListener('alpine:init', () => {
    /**
     * POS camera barcode scanner. Wired via `x-data="barcodeScanner($wire)"`
     * on the search-bar wrapper in the POS terminal. The scan icon opens a
     * camera overlay; ZXing decodes 1D/2D barcodes from the video stream and
     * each hit calls the Livewire `scanBarcode(code)` action, which adds the
     * matching product to the cart (Odoo-style continuous scanning).
     *
     * ZXing is loaded on-demand (`import()` → its own Vite chunk) so it never
     * weighs on the initial terminal load — only the first time a cashier
     * taps the scan icon. Works on iOS Safari / Android / desktop (a single
     * decoder path, no reliance on the patchy native BarcodeDetector API).
     *
     * `wire` is closure-captured (NOT stored on `this`) — Alpine's reactive
     * proxy would wrap it and break `$wire` method calls (see listColumnPicker).
     */
    window.Alpine.data('barcodeScanner', (wire) => ({
        open: false,
        starting: false,
        error: '',          // '' | 'perm' | 'nocam' | 'other'
        errorDetail: '',
        lastMsg: '',
        lastOk: true,
        _controls: null,
        _lastCode: '',
        _lastAt: 0,

        async openScanner() {
            this.open = true;
            this.starting = true;
            this.error = '';
            this.errorDetail = '';
            this.lastMsg = '';
            try {
                const { BrowserMultiFormatReader } = await import('@zxing/browser');
                const reader = new BrowserMultiFormatReader();
                this._controls = await reader.decodeFromConstraints(
                    { video: { facingMode: { ideal: 'environment' } } },
                    this.$refs.video,
                    (result) => { if (result) this.handle(result.getText()); },
                );
            } catch (e) {
                const name = e && e.name ? e.name : '';
                if (name === 'NotAllowedError' || name === 'SecurityError') {
                    this.error = 'perm';
                } else if (name === 'NotFoundError' || name === 'OverconstrainedError') {
                    this.error = 'nocam';
                } else {
                    this.error = 'other';
                    this.errorDetail = (e && e.message) ? e.message : String(e);
                }
            } finally {
                this.starting = false;
            }
        },

        handle(code) {
            const text = (code || '').trim();
            if (text === '') return;
            const now = Date.now();
            // Continuous decode fires every frame the barcode is in view —
            // ignore the same code seen again within 1.2s so one presentation
            // adds the product once.
            if (text === this._lastCode && (now - this._lastAt) < 1200) return;
            this._lastCode = text;
            this._lastAt = now;
            this.beep();
            wire.scanBarcode(text);
        },

        // Server feedback (Livewire dispatches after resolving the code).
        onHit(name) { this.lastOk = true; this.lastMsg = name; },
        onMiss(code) { this.lastOk = false; this.lastMsg = code; },

        close() {
            try { if (this._controls) this._controls.stop(); } catch (e) { /* already stopped */ }
            this._controls = null;
            this.open = false;
        },

        beep() {
            try {
                const Ctx = window.AudioContext || window.webkitAudioContext;
                if (!Ctx) return;
                const ctx = new Ctx();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.frequency.value = 1320;
                osc.connect(gain);
                gain.connect(ctx.destination);
                gain.gain.setValueAtTime(0.06, ctx.currentTime);
                osc.start();
                osc.stop(ctx.currentTime + 0.08);
                osc.onended = () => ctx.close();
            } catch (e) { /* audio optional */ }
        },
    }));

    /**
     * Cloudflare Stream video uploader (rental handover / return videos). Wired
     * via `x-data="streamVideoUpload('handover_video_url', $wire)"`. The big file
     * goes STRAIGHT to Cloudflare (a one-time upload URL minted by our server),
     * so it never passes through the ERP server. On success it writes the public
     * watch URL into the Livewire property named by `target`.
     *
     * `wire` is closure-captured (see the note above) — never stored on `this`.
     */
    window.Alpine.data('streamVideoUpload', (target, wire) => ({
        target,
        uploading: false,
        progress: 0,
        error: '',

        clear() {
            wire.set(this.target, '');
        },

        async pick(event) {
            const file = event.target.files[0];
            if (!file) return;

            this.error = '';
            this.uploading = true;
            this.progress = 0;

            try {
                const csrf = document.querySelector('meta[name=csrf-token]').content;

                // 1) Mint a one-time direct-upload URL from our server.
                const res = await fetch('/app/stream/upload-url', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrf,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ name: file.name }),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'Could not start the upload.');

                // 2) Upload the file directly to Cloudflare (progress via XHR).
                await this.send(data.uploadURL, file);

                // 3) Ask Cloudflare (via our server) for the public watch URL.
                let watchUrl = '';
                for (let i = 0; i < 6 && !watchUrl; i++) {
                    const info = await (await fetch(`/app/stream/${data.uid}/info`, {
                        headers: { 'Accept': 'application/json' },
                    })).json();
                    watchUrl = info.watchUrl || '';
                    if (!watchUrl) await new Promise((r) => setTimeout(r, 1500));
                }

                // 4) Write the public watch URL into the target Livewire prop
                //    (link shows even if Cloudflare is still processing).
                await wire.set(this.target, watchUrl);
            } catch (e) {
                this.error = e.message || 'Upload failed.';
            } finally {
                this.uploading = false;
                event.target.value = '';
            }
        },

        send(url, file) {
            return new Promise((resolve, reject) => {
                const form = new FormData();
                form.append('file', file);
                const xhr = new XMLHttpRequest();
                xhr.open('POST', url, true);
                xhr.upload.onprogress = (e) => {
                    if (e.lengthComputable) this.progress = Math.round((e.loaded / e.total) * 100);
                };
                xhr.onload = () => (xhr.status >= 200 && xhr.status < 300)
                    ? resolve()
                    : reject(new Error('Upload failed (HTTP ' + xhr.status + '). The file may be over 200 MB.'));
                xhr.onerror = () => reject(new Error('Network error during upload.'));
                xhr.send(form);
            });
        },

        async copy(text) {
            try { await navigator.clipboard.writeText(text); } catch (e) { /* ignore */ }
        },
    }));

    /**
     * "Copy" on the limousine queue: lifts the rendered table as TSV so it
     * pastes straight into Excel or Sheets with the columns intact.
     *
     * Client-side on purpose — it copies exactly the page being looked at, and
     * needs no endpoint. Falls back to execCommand because clipboard.writeText
     * requires a secure context, which a plain-HTTP intranet install may not be.
     */
    window.Alpine.data('limoQueueCopy', () => ({
        copied: false,

        copyTable() {
            const table = document.getElementById('limo-queue');
            if (!table) return;

            const text = [...table.querySelectorAll('tr')]
                .map((tr) => [...tr.querySelectorAll('th,td')]
                    .map((cell) => cell.innerText.replace(/\s+/g, ' ').trim())
                    .join('\t'))
                .join('\n');

            const done = () => {
                this.copied = true;
                setTimeout(() => { this.copied = false; }, 2000);
            };

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done).catch(() => {});
                return;
            }

            const area = document.createElement('textarea');
            area.value = text;
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            try { document.execCommand('copy'); done(); } catch (e) { /* ignore */ }
            document.body.removeChild(area);
        },
    }));

    window.Alpine.data('listColumnPicker', () => ({
        init(el, wire) {
            let dragging = null;

            el.addEventListener('dragstart', (e) => {
                const li = e.target.closest('li[data-col]');
                if (li === null || ! el.contains(li)) return;
                dragging = li;
                li.classList.add('opacity-40');
                // Firefox/Safari refuse to start a drag without setData.
                e.dataTransfer.effectAllowed = 'move';
                try {
                    e.dataTransfer.setData('text/plain', li.dataset.col || '');
                } catch (_) {
                    // IE/Edge legacy quirk swallowed.
                }
            });

            el.addEventListener('dragover', (e) => {
                if (dragging === null) return;
                const li = e.target.closest('li[data-col]');
                if (li === null || li === dragging) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                // Insert above when cursor is in upper half of target,
                // else below. Predictable, no flicker.
                const rect = li.getBoundingClientRect();
                const before = (e.clientY - rect.top) < rect.height / 2;
                el.insertBefore(dragging, before ? li : li.nextSibling);
            });

            el.addEventListener('drop', (e) => {
                if (dragging === null) return;
                e.preventDefault();
            });

            el.addEventListener('dragend', () => {
                if (dragging === null) return;
                dragging.classList.remove('opacity-40');
                dragging = null;
                const order = Array.from(el.children)
                    .map((c) => c.getAttribute('data-col'))
                    .filter((v) => v !== null && v !== '');
                // Direct method-call form. $wire.reorderColumns(args)
                // is the Livewire 3 short-hand for $wire.call('reorderColumns', args)
                // and dodges any proxy-chain weirdness around `.call`.
                wire.reorderColumns(order);
            });
        },
    }));
});
