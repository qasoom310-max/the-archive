<div class="mx-auto max-w-2xl p-4 sm:p-6">
    <div class="mb-4 flex items-center gap-2 text-sm text-chrome-500">
        <a href="{{ url('/app/inventory') }}" wire:navigate class="hover:text-primary-700">Inventory</a>
        <span>/</span>
        <a href="{{ url('/app/inventory/transfers') }}" wire:navigate class="hover:text-primary-700">Transfers</a>
        <span>/</span>
        <span class="font-medium text-chrome-700">New</span>
    </div>

    <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-chrome-900/5">
        <h1 class="mb-5 text-lg font-bold text-chrome-900">New transfer</h1>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">Operation type</label>
                <select wire:model.live="operationTypeId" class="o-input">
                    <option value="">— None —</option>
                    @foreach ($types as $t)
                        <option value="{{ $t->id }}">{{ $t->name }} ({{ $t->sequence_code }})</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">Source location</label>
                <select wire:model="sourceId" class="o-input">
                    <option value="">— Select —</option>
                    @foreach ($locations as $l)
                        <option value="{{ $l->id }}">{{ $l->complete_name ?: $l->name }} · {{ $l->type->label() }}</option>
                    @endforeach
                </select>
                @error('sourceId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">Destination location</label>
                <select wire:model="destId" class="o-input">
                    <option value="">— Select —</option>
                    @foreach ($locations as $l)
                        <option value="{{ $l->id }}">{{ $l->complete_name ?: $l->name }} · {{ $l->type->label() }}</option>
                    @endforeach
                </select>
                @error('destId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">Product ref (optional)</label>
                <input type="number" wire:model="productId" class="o-input" placeholder="product id">
                <p class="mt-1 text-xs text-chrome-400">Blank = logical move (no quant change).</p>
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">Quantity</label>
                <input type="number" step="0.001" min="0" wire:model="qty" class="o-input">
                @error('qty') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">Scheduled date</label>
                <x-date-field wire:model="scheduledAt" class="o-input" />
            </div>
        </div>

        <div class="mt-6 flex gap-2">
            <button wire:click="save" class="o-btn-primary">
                <span wire:loading.remove wire:target="save">Create transfer</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
            <a href="{{ url('/app/inventory/transfers') }}" wire:navigate class="o-btn-ghost">Cancel</a>
        </div>
    </div>
</div>
