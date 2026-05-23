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
 * The picker <ul> sits inside a wire:ignore container, so once Alpine
 * initialises the listeners they stay live across toggleColumn round-
 * trips. The container is still inside an x-show="open" wrapper, but
 * x-show uses display:none — the element stays in the DOM, so init
 * still fires on first mount and our listeners are ready when the
 * user opens the dropdown.
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

            el.addEventListener('dragstart', (e) => {
                const li = e.target.closest('li[data-col]');
                if (li === null || ! el.contains(li)) return;
                this.dragging = li;
                li.classList.add('opacity-40');
                // Required by Firefox/Safari to actually start a drag.
                // Chrome tolerates a missing setData, others don't.
                e.dataTransfer.effectAllowed = 'move';
                try {
                    e.dataTransfer.setData('text/plain', li.dataset.col || '');
                } catch (_) {
                    // IE/Edge legacy quirk swallowed — ignored.
                }
            });

            el.addEventListener('dragover', (e) => {
                if (this.dragging === null) return;
                const li = e.target.closest('li[data-col]');
                if (li === null || li === this.dragging) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                // Insert above when cursor is in the upper half of the
                // target row, else below. Predictable, no flicker.
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
                const order = Array.from(el.children)
                    .map((c) => c.getAttribute('data-col'))
                    .filter((v) => v !== null);
                this.wire.call('reorderColumns', order);
            });
        },
    }));
});
