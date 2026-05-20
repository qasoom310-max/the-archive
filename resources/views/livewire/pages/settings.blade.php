<div class="mx-auto max-w-4xl p-6" x-data="{ tab: '{{ array_key_first($tabs) ?? '' }}' }">
    <div class="mb-5 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">Settings</h1>
            <p class="text-sm text-chrome-500">Central configuration for the whole system.</p>
        </div>
        <button wire:click="save" class="o-btn-primary">
            <span wire:loading.remove wire:target="save">Save</span>
            <span wire:loading wire:target="save">Saving…</span>
        </button>
    </div>

    @include('partials.settings-nav', ['active' => 'general'])

    @if ($saved)
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200">
            Settings saved.
        </div>
    @endif

    @if (count($tabs) === 0)
        <p class="rounded-xl border border-dashed border-chrome-300 bg-white p-10 text-center text-sm text-chrome-400">
            No configurable settings yet.
        </p>
    @else
        <div class="mb-5 flex flex-wrap gap-1 border-b border-chrome-200">
            @foreach ($tabs as $group => $ids)
                <button type="button" @click="tab = '{{ $group }}'"
                    :class="tab === '{{ $group }}'
                        ? 'border-primary-600 text-primary-700'
                        : 'border-transparent text-chrome-500 hover:text-chrome-800'"
                    class="-mb-px border-b-2 px-4 py-2 text-sm font-medium">
                    {{ $group }}
                </button>
            @endforeach
        </div>

        @foreach ($tabs as $group => $ids)
            <div x-show="tab === '{{ $group }}'" x-cloak
                class="space-y-5 rounded-xl bg-white p-6 shadow-sm ring-1 ring-chrome-900/5">
                @foreach ($ids as $i)
                    @php $row = $form[$i]; @endphp
                    <div wire:key="setting-{{ $i }}"
                        class="grid items-start gap-2 sm:grid-cols-3">
                        <label class="pt-2 text-sm font-medium text-chrome-800">
                            {{ $row['label'] }}
                            @if ($row['description'])
                                <span class="mt-0.5 block text-xs font-normal text-chrome-400">{{ $row['description'] }}</span>
                            @endif
                        </label>
                        <div class="sm:col-span-2">
                            @if ($row['type'] === 'bool')
                                <button type="button"
                                    wire:click="$set('form.{{ $i }}.value', {{ $row['value'] ? 'false' : 'true' }})"
                                    class="relative inline-flex h-6 w-11 items-center rounded-full transition
                                        {{ $row['value'] ? 'bg-primary-600' : 'bg-chrome-300' }}">
                                    <span class="inline-block size-4 transform rounded-full bg-white transition
                                        {{ $row['value'] ? 'translate-x-6' : 'translate-x-1' }}"></span>
                                </button>
                            @elseif ($row['type'] === 'number')
                                <input type="number" step="0.01" wire:model="form.{{ $i }}.value" class="o-input max-w-xs">
                            @else
                                <input type="text" wire:model="form.{{ $i }}.value" class="o-input">
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endforeach
    @endif
</div>
