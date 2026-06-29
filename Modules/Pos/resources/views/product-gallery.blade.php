<div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
    <div class="mb-3">
        <h2 class="text-sm font-semibold text-chrome-800">{{ __('Gallery images') }}</h2>
        <p class="text-xs text-chrome-500">{{ __('Extra photos shown alongside the main photo. These are pushed to WooCommerce as additional product images.') }}</p>
    </div>

    {{-- Current secondary images — thumbnail grid, each with a remove button. --}}
    @if (count($images) > 0)
        <div class="mb-4 grid grid-cols-3 gap-3 sm:grid-cols-4 md:grid-cols-5">
            @foreach ($images as $i => $path)
                <div class="group relative aspect-square overflow-hidden rounded-lg bg-chrome-100 ring-1 ring-chrome-900/5" wire:key="gallery-{{ $i }}-{{ md5($path) }}">
                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($path) }}"
                        class="size-full object-cover" alt="">
                    @if ($canManage)
                        <button type="button" wire:click="removeImage({{ $i }})"
                            class="absolute end-1 top-1 flex size-6 items-center justify-center rounded-full bg-chrome-900/60 text-white opacity-0 transition group-hover:opacity-100 hover:bg-red-600"
                            title="{{ __('Remove') }}" aria-label="{{ __('Remove') }}">
                            <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z"/>
                            </svg>
                        </button>
                    @endif
                </div>
            @endforeach
        </div>
    @else
        <p class="mb-4 text-sm text-chrome-400">{{ __('No additional images yet.') }}</p>
    @endif

    {{-- Uploader — same direct POST as the engine image widget (FormImageUploadController),
         then hands the stored path to the component via $wire.addImage(path). --}}
    @if ($canManage)
        <div class="flex flex-col gap-1"
             x-data="{
                 busy: false,
                 error: '',
                 async upload(e) {
                     const file = e.target.files[0];
                     if (!file) return;
                     this.busy = true;
                     this.error = '';
                     const data = new FormData();
                     data.append('file', file);
                     data.append('bucket', @js($bucket));
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
                         await $wire.addImage(j.path);
                     } catch (err) {
                         this.error = err.message || (@js(__('Upload failed.')));
                     } finally {
                         this.busy = false;
                         e.target.value = '';
                     }
                 },
             }">
            <input type="file" accept="image/*" @change="upload($event)"
                class="text-sm text-chrome-600 file:me-3 file:rounded-md file:border-0 file:bg-chrome-100 file:px-3 file:py-1.5 file:text-sm">
            <p class="text-xs text-chrome-400">{{ __('Accepted: JPG, PNG, GIF, WebP, AVIF, HEIC, BMP · max 4 MB') }}</p>
            <p x-show="busy" class="text-xs text-chrome-400">{{ __('Uploading…') }}</p>
            <p x-show="error" x-text="error" class="text-xs text-red-600"></p>
        </div>
    @endif
</div>
