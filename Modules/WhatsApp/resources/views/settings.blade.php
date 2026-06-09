<div class="mx-auto max-w-4xl p-4 sm:p-6">
    <div class="mb-5 flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">Settings</h1>
            <p class="text-sm text-chrome-500">WhatsApp Business Cloud API connection.</p>
        </div>
        <button wire:click="save" class="o-btn-primary">
            <span wire:loading.remove wire:target="save">Save</span>
            <span wire:loading wire:target="save">Saving…</span>
        </button>
    </div>

    @include('partials.settings-nav', ['active' => 'whatsapp'])

    @if ($saved)
        <div class="mb-4 rounded-lg bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200">
            WhatsApp settings saved.
        </div>
    @endif

    <div class="space-y-5 rounded-xl bg-white p-6 shadow-sm ring-1 ring-chrome-900/5">
        <div class="grid items-start gap-2 sm:grid-cols-3">
            <label class="pt-2 text-sm font-medium text-chrome-800">
                Enabled
                <span class="mt-0.5 block text-xs font-normal text-chrome-400">Allow the system to send WhatsApp messages.</span>
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

        @php
            $fields = [
                ['phoneNumberId', 'Phone number ID', 'Meta "from" phone number id (Graph API).'],
                ['businessAccountId', 'Business account ID', 'WhatsApp Business Account (WABA) id.'],
                ['apiVersion', 'Graph API version', 'e.g. v21.0'],
                ['templateLanguage', 'Template language', 'Locale code your template is approved under in WhatsApp Manager — e.g. "en" for English, "en_US" for English (US), "ar" for Arabic. Wrong code = #132001 error.'],
                ['fromPhoneLabel', 'Sender label', 'Human-friendly name shown in the UI.'],
            ];
        @endphp
        @foreach ($fields as [$model, $label, $hint])
            <div class="grid items-start gap-2 sm:grid-cols-3" wire:key="wa-{{ $model }}">
                <label class="pt-2 text-sm font-medium text-chrome-800">
                    {{ $label }}
                    <span class="mt-0.5 block text-xs font-normal text-chrome-400">{{ $hint }}</span>
                </label>
                <div class="sm:col-span-2">
                    <input type="text" wire:model="{{ $model }}" class="o-input">
                </div>
            </div>
        @endforeach

        @php
            $secrets = [
                ['accessToken', 'Access token', $hasAccessToken],
                ['appSecret', 'App secret', $hasAppSecret],
                ['webhookVerifyToken', 'Webhook verify token', $hasWebhookVerifyToken],
            ];
        @endphp
        @foreach ($secrets as [$model, $label, $isSet])
            <div class="grid items-start gap-2 sm:grid-cols-3" wire:key="wa-{{ $model }}">
                <label class="pt-2 text-sm font-medium text-chrome-800">
                    {{ $label }}
                    <span class="mt-0.5 block text-xs font-normal {{ $isSet ? 'text-emerald-600' : 'text-chrome-400' }}">
                        {{ $isSet ? 'Configured — leave blank to keep current.' : 'Not set.' }}
                    </span>
                </label>
                <div class="sm:col-span-2">
                    <input type="password" autocomplete="new-password"
                        wire:model="{{ $model }}" class="o-input"
                        placeholder="{{ $isSet ? '•••••••• (unchanged)' : '' }}">
                </div>
            </div>
        @endforeach

        <div class="grid items-start gap-2 border-t border-chrome-100 pt-5 sm:grid-cols-3">
            <label class="pt-2 text-sm font-medium text-chrome-800">
                Webhook URL
                <span class="mt-0.5 block text-xs font-normal text-chrome-400">
                    Paste into Meta → WhatsApp → Configuration. Use the verify token above.
                </span>
            </label>
            <div class="sm:col-span-2">
                <input type="text" readonly value="{{ $webhookUrl }}"
                    class="o-input bg-chrome-50 text-chrome-500"
                    onclick="this.select()">
            </div>
        </div>
    </div>
</div>
