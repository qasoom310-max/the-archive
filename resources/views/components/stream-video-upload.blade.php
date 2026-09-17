@props(['target', 'url' => ''])

{{-- Uploads a video, then writes its public URL into the Livewire property
     named by `target` (e.g. handover_video_url). Replaces a manual "paste a
     link" input. Where the video goes is config('erp.video_storage'):
       local      - this server, in 2 MB chunks (VideoUploadController)
       cloudflare - straight to Cloudflare Stream, never through this server --}}
<div x-data="streamVideoUpload(@js($target), $wire, @js(config('erp.video_storage') === 'cloudflare' ? 'cloudflare' : 'local'))">
    @if ($url !== '')
        {{-- Uploaded → share link + actions --}}
        <div class="space-y-2" x-show="!uploading">
            <div class="flex items-center gap-2">
                <svg class="size-4 shrink-0 text-emerald-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0l-3.5-3.5a1 1 0 1 1 1.4-1.4l2.8 2.79 6.8-6.79a1 1 0 0 1 1.4 0Z" clip-rule="evenodd"/>
                </svg>
                <a href="{{ $url }}" target="_blank" rel="noopener"
                    class="truncate text-sm text-primary-700 hover:underline">{{ $url }}</a>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <button type="button" x-on:click="copy(@js($url))"
                    class="rounded-md bg-chrome-100 px-3 py-1.5 text-xs font-medium text-chrome-700 hover:bg-chrome-200">
                    {{ __('Copy link') }}
                </button>
                <label class="cursor-pointer text-xs font-medium text-primary-700 hover:underline">
                    {{ __('Replace') }}
                    <input type="file" accept="video/*" class="hidden" x-on:change="pick($event)">
                </label>
                <button type="button" x-on:click="clear()"
                    class="text-xs text-red-600 hover:underline">{{ __('Remove') }}</button>
            </div>
        </div>
    @else
        {{-- Empty → Choose video uploader --}}
        <label class="flex cursor-pointer items-center gap-3 rounded-md border border-dashed border-chrome-300 px-3 py-3 hover:bg-chrome-50"
            x-show="!uploading">
            <span class="rounded bg-chrome-100 px-3 py-1.5 text-sm font-medium text-chrome-700">{{ __('Choose video') }}</span>
            <span class="text-sm text-chrome-400">{{ __('No file chosen') }}</span>
            <input type="file" accept="video/*" class="hidden" x-on:change="pick($event)">
        </label>
    @endif

    {{-- Uploading progress --}}
    <div x-show="uploading" x-cloak class="mt-1">
        <div class="h-2 w-full overflow-hidden rounded-full bg-chrome-100">
            <div class="h-full bg-primary-500 transition-all" :style="`width: ${progress}%`"></div>
        </div>
        <p class="mt-1 text-xs text-chrome-500"
            x-text="progress < 100 ? '{{ __('Uploading') }} ' + progress + '%' : '{{ __('Processing…') }}'"></p>
    </div>

    <p x-show="error" x-cloak class="mt-2 text-xs text-red-600" x-text="error"></p>
</div>
