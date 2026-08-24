<div class="mx-auto max-w-4xl p-4 sm:p-6">
    <div class="mb-4 flex items-center gap-2 text-sm text-chrome-500">
        <a href="{{ url('/app/accounting') }}" wire:navigate class="hover:text-primary-700">{{ __('Accounting') }}</a>
        <span>/</span>
        <a href="{{ url('/app/accounting/journal_entry') }}" wire:navigate class="hover:text-primary-700">{{ __('Journal Entries') }}</a>
        <span>/</span>
        <span class="font-medium text-chrome-700">{{ $entry?->number ?? __('New entry') }}</span>
    </div>

    <livewire:views.form-view
        :model="\Modules\Accounting\Models\JournalEntry::class"
        model-key="accounting.journal_entry"
        :record-id="$entry?->id"
        title="{{ $entry ? __('Edit entry') : __('New entry') }}"
        :key="'accounting-journal-entry-form-' . ($entry?->id ?? 'new')" />

    @if ($entry !== null)
        <div class="mt-6 rounded-lg border border-chrome-200 bg-white p-4">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-chrome-900">{{ __('Lines') }}</h2>
                <div class="text-sm text-chrome-500">
                    {{ __('Debits') }}: <span class="font-medium text-chrome-900">{{ \App\Erp\Views\ValueFormat::money($entry->totalDebit()) }}</span>
                    &nbsp;·&nbsp;
                    {{ __('Credits') }}: <span class="font-medium text-chrome-900">{{ \App\Erp\Views\ValueFormat::money($entry->totalCredit()) }}</span>
                </div>
            </div>
            <div class="overflow-x-auto">
            <table class="w-full min-w-[520px] text-sm">
                <thead class="border-b border-chrome-200 text-chrome-500">
                    <tr>
                        <th class="py-2 text-start">{{ __('Account') }}</th>
                        <th class="py-2 text-end">{{ __('Debit') }}</th>
                        <th class="py-2 text-end">{{ __('Credit') }}</th>
                        <th class="py-2 text-start">{{ __('Memo') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-chrome-100">
                    @forelse ($entry->items()->with('account')->get() as $line)
                        <tr>
                            <td class="py-2">
                                <span class="font-mono text-xs text-chrome-500">{{ $line->account?->code }}</span>
                                <span class="ms-2 text-chrome-900">{{ $line->account?->name }}</span>
                            </td>
                            <td class="py-2 text-end font-mono">{{ $line->debit > 0 ? \App\Erp\Views\ValueFormat::money($line->debit) : '—' }}</td>
                            <td class="py-2 text-end font-mono">{{ $line->credit > 0 ? \App\Erp\Views\ValueFormat::money($line->credit) : '—' }}</td>
                            <td class="py-2 text-chrome-600">{{ $line->memo }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-3 text-center text-chrome-400">{{ __('No lines yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
            </div>
        </div>

        <livewire:chatter :chatterable="$entry" :key="'je-chatter-' . $entry->id" />
    @endif
</div>
