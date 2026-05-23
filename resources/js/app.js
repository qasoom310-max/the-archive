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
 * Event delegation on the <ul> (not per-<li>) so handlers survive
 * Livewire DOM morphs after every toggleColumn round-trip: the
 * children get replaced but the <ul> is the same node, so our
 * listeners stay live.
 *
 * On drop, posts the new field-order array to Livewire's reorderColumns
 * action which persists it per (user, model) in user_view_preferences.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('listColumnPicker', () => ({
        ul: null,
        wire: null,
        dragging: null,

        init(el, wire) {
            this.ul = el;
            this.wire = wire;
            this.markChildrenDraggable();

            el.addEventListener('dragstart', (e) => {
                const li = e.target.closest('li[data-col]');
                if (li === null || ! el.contains(li)) return;
                this.dragging = li;
                li.classList.add('opacity-40');
                e.dataTransfer.effectAllowed = 'move';
            });

            el.addEventListener('dragover', (e) => {
                if (this.dragging === null) return;
                const li = e.target.closest('li[data-col]');
                if (li === null || li === this.dragging) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                // Insert above when cursor is in upper half of target,
                // else below. Predictable, no flicker.
                const rect = li.getBoundingClientRect();
                const before = (e.clientY - rect.top) < rect.height / 2;
                el.insertBefore(this.dragging, before ? li : li.nextSibling);
            });

            el.addEventListener('drop', (e) => {
                if (this.dragging === null) return;
                e.preventDefault();
            });

            el.addEventListener('dragend', () => {
                if (this.dragging === null) return;
                this.dragging.classList.remove('opacity-40');
                this.dragging = null;
                const order = Array.from(el.children).map(
                    (c) => c.getAttribute('data-col')
                ).filter((v) => v !== null);
                this.wire.call('reorderColumns', order);
            });

            // Livewire replaces the <li> children after each round-trip
            // (e.g. toggleColumn). Re-set draggable on the new set so
            // drag still works after a check/uncheck.
            new MutationObserver(() => this.markChildrenDraggable())
                .observe(el, { childList: true });
        },

        markChildrenDraggable() {
            for (const li of this.ul.children) {
                li.setAttribute('draggable', 'true');
            }
        },
    }));
});
