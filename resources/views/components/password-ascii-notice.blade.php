{{--
    Transient amber pill shown when a password input rejects a non-ASCII
    keystroke. Consumes `blocked` from the parent Alpine scope (set by
    `notifyBlocked()` on a vetoed `beforeinput`) and auto-fades 2.5s
    after the last attempt — debounced inside `notifyBlocked()` so a
    held key doesn't fade mid-attempt.

    Renders nothing visible until `blocked === true`; the slide-down +
    fade-in is purely cosmetic. Inline icon uses `currentColor` so the
    amber palette flows through without an extra colour declaration.
--}}
<p x-show="blocked" x-cloak
    x-transition:enter="transition ease-out duration-150"
    x-transition:enter-start="opacity-0 -translate-y-0.5"
    x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    role="status" aria-live="polite"
    class="mt-1.5 inline-flex items-center gap-1.5 rounded-md bg-amber-50 px-2 py-1 text-xs font-medium text-amber-700 ring-1 ring-amber-200">
    <svg class="size-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
        <path fill-rule="evenodd" d="M18 10A8 8 0 1 1 2 10a8 8 0 0 1 16 0Zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0Zm-1 9a1 1 0 0 1-1-1V9a1 1 0 1 1 2 0v5a1 1 0 0 1-1 1Z" clip-rule="evenodd"/>
    </svg>
    {{ __('English characters only.') }}
</p>
