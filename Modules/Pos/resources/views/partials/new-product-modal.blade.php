{{-- Shared inline "New product" modal. Used by any Livewire component that
     uses the CreatesProductInline trait (Purchases bill editor, POS recipe
     editor, …). Expects in scope: $addingProduct, $newProduct.* (wire models),
     $unitOptions, $categories, and the host's saveProduct / closeProductModal
     actions. Lives OUTSIDE the host's own <form> so its inputs/submit are
     independent. --}}
@if ($addingProduct)
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
        x-data x-on:keydown.escape.window="$wire.closeProductModal()"
        x-init="$nextTick(() => $refs.productName && $refs.productName.focus())">
        <div class="absolute inset-0 bg-chrome-900/40" wire:click="closeProductModal"></div>
        <div class="relative w-full max-w-lg rounded-xl bg-white p-5 shadow-pop ring-1 ring-chrome-900/5">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-chrome-800">{{ __('New product') }}</h3>
                <button type="button" wire:click="closeProductModal" class="text-chrome-400 transition hover:text-chrome-700" title="{{ __('Close') }}">
                    <svg class="size-5" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/></svg>
                </button>
            </div>
            <form wire:submit.prevent="saveProduct">
                <div class="max-h-[60vh] space-y-3 overflow-y-auto pe-1">
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Name') }} <span class="text-red-500">*</span></label>
                        <input type="text" wire:model="newProduct.name" x-ref="productName"
                            placeholder="{{ __('Product name') }}" class="o-input">
                        @error('newProduct.name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Sale Price') }}</label>
                            <input type="number" step="any" min="0" wire:model="newProduct.price" class="o-input">
                            @error('newProduct.price') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Cost Price') }}</label>
                            <input type="number" step="any" min="0" wire:model="newProduct.cost_price" class="o-input">
                            @error('newProduct.cost_price') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Tax %') }}</label>
                            <input type="number" step="any" min="0" wire:model="newProduct.tax_rate" class="o-input">
                            @error('newProduct.tax_rate') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Barcode') }}</label>
                            <input type="text" wire:model="newProduct.barcode" class="o-input">
                            @error('newProduct.barcode') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Stock on hand') }}</label>
                            <input type="number" step="any" min="0" wire:model="newProduct.stock_on_hand" class="o-input">
                            @error('newProduct.stock_on_hand') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Unit') }}</label>
                            <select wire:model="newProduct.unit" class="o-input">
                                @foreach ($unitOptions as $opt)
                                    <option value="{{ $opt['value'] }}">{{ __($opt['label']) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Reorder point') }}</label>
                            <input type="number" step="any" min="0" wire:model="newProduct.reorder_point" class="o-input">
                            @error('newProduct.reorder_point') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Category') }}</label>
                            <select wire:model="newProduct.pos_category_id" class="o-input">
                                <option value="">—</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" wire:model="newProduct.active"
                            class="rounded border-chrome-300 text-primary-600 focus:ring-primary-500">
                        <span class="text-sm text-chrome-600">{{ __('Active') }}</span>
                    </label>

                    {{-- Photo — direct synchronous upload to FormImageUploadController
                         (bucket pos_products), mirroring the engine image widget. --}}
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('Photo') }}</label>
                        <div class="flex items-center gap-4"
                            x-data="{
                                busy: false,
                                error: '',
                                previewUrl: '',
                                async upload(e) {
                                    const file = e.target.files[0];
                                    if (!file) return;
                                    this.busy = true;
                                    this.error = '';
                                    const data = new FormData();
                                    data.append('file', file);
                                    data.append('bucket', 'pos_products');
                                    try {
                                        const r = await fetch(@js(route('form.upload-image')), {
                                            method: 'POST',
                                            headers: {
                                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                                'Accept': 'application/json',
                                            },
                                            body: data,
                                            credentials: 'same-origin',
                                        });
                                        if (!r.ok) {
                                            const j = await r.json().catch(() => ({}));
                                            this.error = (j.errors && j.errors.file && j.errors.file[0]) || j.message || (@js(__('Upload failed.')));
                                            return;
                                        }
                                        const j = await r.json();
                                        this.previewUrl = j.url;
                                        await $wire.set('newProduct.image_path', j.path);
                                    } catch (err) {
                                        this.error = err.message || (@js(__('Upload failed.')));
                                    } finally {
                                        this.busy = false;
                                    }
                                },
                            }">
                            <span class="flex size-16 items-center justify-center overflow-hidden rounded-lg bg-chrome-100 text-chrome-400">
                                <template x-if="previewUrl">
                                    <img :src="previewUrl" class="size-full object-cover">
                                </template>
                                <template x-if="!previewUrl">
                                    <svg class="size-7" viewBox="0 0 20 20" fill="currentColor"><path d="M3 5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5Zm2 0v7.59l2.3-2.3a1 1 0 0 1 1.4 0L11 12.6l1.3-1.3a1 1 0 0 1 1.4 0L15 12.59V5H5Zm2.5 2a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Z"/></svg>
                                </template>
                            </span>
                            <div class="flex flex-col gap-1">
                                <input type="file" accept="image/*" @change="upload($event)"
                                    class="text-sm text-chrome-600 file:me-3 file:rounded-md file:border-0 file:bg-chrome-100 file:px-3 file:py-1.5 file:text-sm">
                                <p class="text-xs text-chrome-400">{{ __('Accepted: JPG, PNG, GIF, WebP, AVIF, HEIC, BMP · max 4 MB') }}</p>
                                <p x-show="busy" class="text-xs text-chrome-400">{{ __('Uploading…') }}</p>
                                <p x-show="error" x-text="error" class="text-xs text-red-600"></p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="mt-4 flex justify-end gap-2 border-t border-chrome-100 pt-3">
                    <button type="button" wire:click="closeProductModal"
                        class="rounded-md px-3 py-1.5 text-sm font-medium text-chrome-600 ring-1 ring-chrome-300 hover:bg-chrome-50">
                        {{ __('Cancel') }}
                    </button>
                    <button type="submit" class="o-btn-primary">{{ __('Add product') }}</button>
                </div>
            </form>
        </div>
    </div>
@endif
