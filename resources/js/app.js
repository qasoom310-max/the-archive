import './bootstrap';

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
