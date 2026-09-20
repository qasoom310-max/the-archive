<div class="mx-auto max-w-4xl p-4 sm:p-6">
    <div class="mb-5 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">Settings</h1>
            <p class="text-sm text-chrome-500">WhatsApp staff assistant — quote, book and send documents by chatting.</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ url('/app/settings/whatsapp-assistant/chat') }}" wire:navigate class="text-sm font-medium text-primary-700 hover:underline">
                Try it here (no phone needed)
            </a>
            <button wire:click="save" class="o-btn-primary">
                <span wire:loading.remove wire:target="save">Save</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
        </div>
    </div>

    @include('partials.settings-nav', ['active' => 'whatsapp_assistant'])

    @if ($saved)
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200">
            Assistant settings saved.
        </div>
    @endif

    {{-- Connection checklist --}}
    <div class="mb-5 space-y-3 rounded-xl bg-white p-6 shadow-sm ring-1 ring-chrome-900/5">
        <h2 class="text-sm font-semibold text-chrome-900">Connection</h2>
        <div class="flex items-center gap-2 text-sm">
            @if ($metaReady)
                <span class="rounded bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">Ready</span>
                <span class="text-chrome-600">Meta credentials are set on the WhatsApp tab.</span>
            @else
                <span class="rounded bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700">Missing</span>
                <span class="text-chrome-600">
                    Set the Phone number ID, access token, app secret and webhook verify token on the
                    <a href="{{ url('/app/settings/whatsapp') }}" wire:navigate class="font-medium text-primary-700 hover:underline">WhatsApp tab</a>.
                </span>
            @endif
        </div>
        @if ($webhookUrl !== null)
            <div>
                <p class="text-xs text-chrome-500">Callback URL to paste into Meta (subscribe to the <code>messages</code> field). The verify token is the one on the WhatsApp tab.</p>
                <div x-data="{ copied: false }" class="mt-1 flex items-center gap-2">
                    <code class="min-w-0 flex-1 truncate rounded bg-chrome-100 px-2 py-1.5 text-xs text-chrome-800" dir="ltr">{{ $webhookUrl }}</code>
                    <button type="button" class="rounded-md bg-chrome-100 px-3 py-1.5 text-xs font-medium text-chrome-700 hover:bg-chrome-200"
                        x-on:click="$store.clip ? $store.clip.copy(@js($webhookUrl)) : navigator.clipboard.writeText(@js($webhookUrl)); copied = true; setTimeout(() => copied = false, 1500)">
                        <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                    </button>
                </div>
            </div>
        @endif
    </div>

    <div class="mb-5 space-y-5 rounded-xl bg-white p-6 shadow-sm ring-1 ring-chrome-900/5">
        <div class="grid items-start gap-2 sm:grid-cols-3">
            <label class="pt-2 text-sm font-medium text-chrome-800">
                Assistant on
                <span class="mt-0.5 block text-xs font-normal text-chrome-400">Master switch. Off = the bot ignores every message.</span>
            </label>
            <div class="sm:col-span-2">
                <button type="button" wire:click="$toggle('enabled')"
                    class="relative inline-flex h-6 w-11 items-center rounded-full transition {{ $enabled ? 'bg-primary-500' : 'bg-chrome-300' }}">
                    <span class="inline-block size-4 transform rounded-full bg-white transition {{ $enabled ? 'translate-x-6' : 'translate-x-1' }}"></span>
                </button>
                @error('enabled') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid items-start gap-2 sm:grid-cols-3">
            <label class="pt-2 text-sm font-medium text-chrome-800">
                Claude API key
                <span class="mt-0.5 block text-xs font-normal text-chrome-400">{{ $hasApiKey ? 'A key is saved. Leave blank to keep it.' : 'Not set.' }}</span>
            </label>
            <div class="sm:col-span-2">
                <input type="password" wire:model="aiApiKey" autocomplete="off" class="o-input" placeholder="{{ $hasApiKey ? '••••••••' : 'sk-ant-…' }}" dir="ltr">
            </div>
        </div>

        <div class="grid items-start gap-2 sm:grid-cols-3">
            <label class="pt-2 text-sm font-medium text-chrome-800">
                Model
                <span class="mt-0.5 block text-xs font-normal text-chrome-400">Default claude-opus-5.</span>
            </label>
            <div class="sm:col-span-2">
                <input type="text" wire:model="aiModel" class="o-input" dir="ltr">
                @error('aiModel') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>
    </div>

    {{-- Authorised numbers --}}
    <div class="space-y-4 rounded-xl bg-white p-6 shadow-sm ring-1 ring-chrome-900/5">
        <div>
            <h2 class="text-sm font-semibold text-chrome-900">Authorised staff</h2>
            <p class="text-xs text-chrome-500">Only these WhatsApp numbers get an answer. Each acts as its ERP user, with that user's permissions.</p>
        </div>

        <div class="divide-y divide-chrome-100 rounded-lg ring-1 ring-chrome-200">
            @forelse ($staff as $row)
                <div class="flex items-center justify-between gap-3 px-3 py-2 text-sm" wire:key="staff-{{ $row->id }}">
                    <span class="font-medium text-chrome-800" dir="ltr">+{{ $row->phone }}</span>
                    <span class="min-w-0 flex-1 truncate text-chrome-600">{{ $row->user?->name ?? 'Unknown user' }}</span>
                    <button type="button" wire:click="removeStaff({{ $row->id }})" wire:confirm="Remove this number?"
                        class="text-xs text-red-600 hover:underline">Remove</button>
                </div>
            @empty
                <p class="px-3 py-3 text-sm text-chrome-400">No numbers authorised yet.</p>
            @endforelse
        </div>

        <div class="grid gap-2 sm:grid-cols-[1fr_1fr_auto]">
            <div>
                <input type="text" wire:model="newPhone" class="o-input" placeholder="97338467744" dir="ltr" inputmode="tel">
                @error('newPhone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <select wire:model="newUserId" class="o-input">
                    <option value="">Acts as ERP user…</option>
                    @foreach ($users as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}{{ $u->email ? ' — ' . $u->email : '' }}</option>
                    @endforeach
                </select>
                @error('newUserId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>
            <button type="button" wire:click="addStaff" class="o-btn-primary">Add</button>
        </div>
    </div>
</div>
