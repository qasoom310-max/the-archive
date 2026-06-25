<div class="mx-auto max-w-4xl p-4 sm:p-6">
    <div class="mb-5 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">Settings</h1>
            <p class="text-sm text-chrome-500">Cloudflare Stream — upload rental videos and share a link.</p>
        </div>
        <button wire:click="save" class="o-btn-primary">
            <span wire:loading.remove wire:target="save">Save</span>
            <span wire:loading wire:target="save">Saving…</span>
        </button>
    </div>

    @include('partials.settings-nav', ['active' => 'stream'])

    @if ($saved)
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200">
            Cloudflare Stream settings saved.
        </div>
    @endif

    <div class="space-y-5 rounded-xl bg-white p-6 shadow-sm ring-1 ring-chrome-900/5">
        <div class="grid items-start gap-2 sm:grid-cols-3">
            <label class="pt-2 text-sm font-medium text-chrome-800">
                Enabled
                <span class="mt-0.5 block text-xs font-normal text-chrome-400">Allow uploading videos to Cloudflare Stream.</span>
            </label>
            <div class="sm:col-span-2">
                <button type="button" wire:click="$toggle('enabled')"
                    class="relative inline-flex h-6 w-11 items-center rounded-full transition
                        {{ $enabled ? 'bg-primary-500' : 'bg-chrome-300' }}">
                    <span class="inline-block size-4 transform rounded-full bg-white transition
                        {{ $enabled ? 'translate-x-6' : 'translate-x-1' }}"></span>
                </button>
                @error('enabled') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid items-start gap-2 sm:grid-cols-3">
            <label class="pt-2 text-sm font-medium text-chrome-800">
                Account ID
                <span class="mt-0.5 block text-xs font-normal text-chrome-400">From your Cloudflare dashboard URL (…/<b>accountid</b>/stream/videos).</span>
            </label>
            <div class="sm:col-span-2">
                <input type="text" wire:model="accountId" class="o-input" placeholder="0e66c6c0e60b020b1cb5daf292a64a39">
                @error('accountId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid items-start gap-2 sm:grid-cols-3">
            <label class="pt-2 text-sm font-medium text-chrome-800">
                API token
                <span class="mt-0.5 block text-xs font-normal {{ $hasApiToken ? 'text-emerald-600' : 'text-chrome-400' }}">
                    {{ $hasApiToken ? 'Configured — leave blank to keep current.' : 'Not set.' }}
                    A token with the <b>Stream: Edit</b> permission.
                </span>
            </label>
            <div class="sm:col-span-2">
                <input type="password" autocomplete="new-password" wire:model="apiToken" class="o-input"
                    placeholder="{{ $hasApiToken ? '•••••••• (unchanged)' : '' }}">
            </div>
        </div>

        <div class="grid items-start gap-2 border-t border-chrome-100 pt-5 sm:grid-cols-3">
            <label class="pt-2 text-sm font-medium text-chrome-800">
                How to get a token
                <span class="mt-0.5 block text-xs font-normal text-chrome-400">
                    Cloudflare → My Profile → API Tokens → Create Token → Stream (Edit). Then enable above and Save.
                </span>
            </label>
            <div class="sm:col-span-2 self-center text-xs text-chrome-500">
                Once configured, a rental order shows <b>Pickup video</b> and <b>Return video</b> upload boxes.
            </div>
        </div>
    </div>
</div>
