<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <x-page-header :title="__('Expenses')" :subtitle="__('Running costs.')" icon="wallet" accent="indigo">
        <x-slot:actions>
            <span class="inline-flex items-center gap-1.5 rounded-lg bg-rose-50 px-3 py-1.5 text-sm font-medium text-rose-700 ring-1 ring-rose-100">
                {{ __('Total') }}: <span class="font-bold">{{ \App\Erp\Views\ValueFormat::money($total) }}</span>
            </span>
            @if ($canManage)
                <button type="button" onclick="document.getElementById('import-expenses').classList.toggle('hidden')" class="o-btn-ghost hidden sm:inline-flex">{{ __('Import') }}</button>
            @endif
            <a href="{{ url('/app/limousine/expense/vat-report') }}" wire:navigate class="o-btn-ghost hidden sm:inline-flex" data-vat-report>{{ __('VAT report') }}</a>
            <a href="{{ url('/app/limousine/expense/scan') }}" wire:navigate class="o-btn-ghost" data-scan-bill>
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M1 8a2 2 0 0 1 2-2h.93a2 2 0 0 0 1.664-.89l.812-1.22A2 2 0 0 1 8.07 3h3.86a2 2 0 0 1 1.664.89l.812 1.22A2 2 0 0 0 16.07 6H17a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8Zm13.5 3a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM10 14a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z" clip-rule="evenodd"/></svg>
                {{ __('Scan a bill') }}
            </a>
            <a href="{{ url('/app/limousine/expense/new') }}" wire:navigate class="o-btn-primary">
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 5a1 1 0 0 1 1 1v3h3a1 1 0 1 1 0 2h-3v3a1 1 0 1 1-2 0v-3H6a1 1 0 1 1 0-2h3V6a1 1 0 0 1 1-1Z"/></svg>
                {{ __('New') }}
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- Import expenses from a CSV (managers). Direct POST — Hostinger-safe.
         The expected columns are the same shape this screen's own export
         prints. A row is created directly at the figures given. --}}
    @if ($canManage)
        <div id="import-expenses" class="mb-4 {{ $errors->any() ? '' : 'hidden' }} rounded-2xl border border-dashed border-chrome-300 bg-white p-4">
            <h3 class="mb-1 text-sm font-semibold text-chrome-800">{{ __('Import expenses (CSV)') }}</h3>
            <p class="mb-3 text-xs text-chrome-500">{{ __('Columns: Reference, Date, Category, Paid to, Amount. Other columns are ignored. The same amount, date and payee seen before is skipped.') }}</p>
            <form method="POST" action="{{ url('/app/limousine/expense/import') }}" enctype="multipart/form-data" class="flex flex-wrap items-end gap-3">
                @csrf
                <input type="file" name="file" accept=".csv,text/csv,text/plain" required class="text-sm">
                <button type="submit" class="o-btn-primary text-sm">{{ __('Import') }}</button>
            </form>
            @error('file')<p class="mt-2 text-xs font-medium text-red-600">{{ $message }}</p>@enderror
            @if (session('toast'))
                <p class="mt-2 text-xs font-medium text-emerald-600">{{ session('toast') }}</p>
            @endif
        </div>
    @endif

    <livewire:views.list-view
        :model="\Modules\Limousine\Models\LimoExpense::class"
        model-key="limousine.expense"
        title="Expenses"
        :key="'limo-expenses'" />
</div>
