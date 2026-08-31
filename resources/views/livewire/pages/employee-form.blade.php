<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <div class="mb-4 flex items-center justify-between gap-3">
        <div class="flex items-center gap-2 text-sm text-chrome-500">
            <a href="{{ url('/hr/employees') }}" wire:navigate class="hover:text-primary-700">{{ __('Employees') }}</a>
            <span>/</span>
            <span class="font-medium text-chrome-700">{{ $form['name'] !== '' ? $form['name'] : __('New employee') }}</span>
        </div>
        @if ($id)
            <a href="{{ url('/hr/employee/' . $id . '/payroll') }}" wire:navigate
                class="inline-flex items-center gap-1 rounded-md px-3 py-1.5 text-sm font-medium text-chrome-600 ring-1 ring-chrome-300 hover:bg-chrome-50">
                {{ __('Payroll') }} →
            </a>
        @endif
    </div>

    <form wire:submit.prevent="save" class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Name') }} <span class="text-red-500">*</span></label>
                <input type="text" wire:model="form.name" class="o-input">
                @error('form.name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Position') }}</label>
                <input type="text" wire:model="form.position" class="o-input">
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Basic salary') }}</label>
                <input type="number" step="0.001" min="0" wire:model="form.basic_salary" class="o-input">
                @error('form.basic_salary') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Join date') }}</label>
                <x-date-field wire:model="form.join_date" class="o-input" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Phone') }}</label>
                <input type="text" wire:model="form.phone" class="o-input">
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Email') }}</label>
                <input type="email" wire:model="form.email" class="o-input">
                @error('form.email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('CPR / ID') }}</label>
                <input type="text" wire:model="form.cpr" class="o-input">
            </div>
            <div class="flex items-end">
                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" wire:model="form.active" class="rounded border-chrome-300 text-primary-600 focus:ring-primary-500">
                    <span class="text-sm text-chrome-600">{{ __('Active') }}</span>
                </label>
            </div>
        </div>

        {{-- Signed agreement upload (synchronous POST — reliable on the host). --}}
        <div class="mt-4 border-t border-chrome-100 pt-4">
            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Signed agreement') }}</label>
            <div class="flex flex-wrap items-center gap-3"
                x-data="{
                    busy: false,
                    async pick(e) {
                        const f = e.target.files[0]; if (!f) return;
                        this.busy = true;
                        const fd = new FormData();
                        fd.append('file', f);
                        fd.append('bucket', 'employee_agreements');
                        fd.append('_token', '{{ csrf_token() }}');
                        try {
                            const r = await fetch('{{ url('/form/upload-file') }}', { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
                            if (r.ok) { const j = await r.json(); $wire.set('agreementPath', j.path); }
                            else { alert('{{ __('Upload failed — PDF or image, max 8 MB.') }}'); }
                        } finally { this.busy = false; e.target.value = ''; }
                    }
                }">
                <input type="file" accept="application/pdf,image/*" @change="pick($event)" :disabled="busy"
                    class="text-sm file:mr-3 file:rounded-md file:border-0 file:bg-primary-400 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-chrome-900 hover:file:bg-primary-500">
                <span x-show="busy" x-cloak class="text-xs text-chrome-500">{{ __('Uploading…') }}</span>
                @if ($agreementUrl)
                    <a href="{{ $agreementUrl }}" target="_blank" class="text-sm font-medium text-primary-700 hover:underline">{{ __('View agreement') }}</a>
                    <button type="button" wire:click="$set('agreementPath', null)" class="text-xs text-red-500 hover:underline">{{ __('Remove') }}</button>
                @endif
            </div>
            <p class="mt-1 text-xs text-chrome-400">{{ __('Sign the contract on paper, scan it, and upload the PDF/image here.') }}</p>
        </div>

        <div class="mt-4">
            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Notes') }}</label>
            <textarea wire:model="form.notes" rows="2" class="o-input resize-none"></textarea>
        </div>

        <div class="mt-5 flex justify-end">
            <button type="submit" class="o-btn-primary">{{ __('Save') }}</button>
        </div>
    </form>
</div>
