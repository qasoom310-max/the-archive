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
