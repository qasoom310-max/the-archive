<div class="mx-auto max-w-6xl p-4 sm:p-6">
    <div class="mb-4 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ __('Journal Entries') }}</h1>
            <p class="text-sm text-chrome-500">{{ __('Every posted booking — POS sales, vendor bills, and manual entries.') }}</p>
        </div>
        @if ($canCreate)
            <a href="{{ url('/app/accounting/journal_entry/new') }}" wire:navigate class="o-btn-primary">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                {{ __('New') }}
            </a>
        @endif
    </div>

    <livewire:views.list-view
        :model="\Modules\Accounting\Models\JournalEntry::class"
        model-key="accounting.journal_entry"
        title="Journal Entries"
        :key="'accounting-journal-entries-list'" />
</div>
