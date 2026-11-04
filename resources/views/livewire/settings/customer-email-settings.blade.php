@php
    /** @var \App\Erp\Mail\EmailTemplates $templates */
    $fieldLabels = [
        'subject' => __('Subject line'),
        'heading' => __('Heading'),
        'intro' => __('Text above the details box'),
        'outro' => __('Text below the details box'),
        'button' => __('Button label'),
    ];
    $tabClass = fn (bool $on): string => $on
        ? 'bg-primary-400 text-chrome-900 ring-primary-400'
        : 'bg-white text-chrome-600 ring-chrome-200 hover:text-chrome-900';
    $chips = function (array $names): string {
        return collect($names)->map(fn (string $n): string => '<code class="rounded bg-chrome-100 px-1.5 py-0.5 text-[11px] text-chrome-700" dir="ltr">{' . e($n) . '}</code>')->implode(' ');
    };
@endphp

<div class="mx-auto max-w-7xl p-4 sm:p-6">
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ __('Settings') }}</h1>
            <p class="text-sm text-chrome-500">{{ __('Customer emails: the look, signature and wording of every email your customers receive.') }}</p>
        </div>
        <button wire:click="save" wire:loading.attr="disabled" class="o-btn-primary">
            <span wire:loading.remove wire:target="save">{{ __('Save') }}</span>
            <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
        </button>
    </div>

    @include('partials.settings-nav', ['active' => 'customer_emails'])

    @if (! $enabled)
        <div class="mb-4 rounded-lg bg-amber-50 px-4 py-2 text-sm text-amber-800 ring-1 ring-amber-200">
            {{ __('Automatic customer emails are OFF for this database. Turn them on under "Sending". The Send buttons (quotations, receipts, coupons, service orders, agreements) still use this design.') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 rounded-lg bg-red-50 px-4 py-2 text-sm text-red-700 ring-1 ring-red-200">
            {{ __('Some fields need fixing before this can be saved.') }}
            <ul class="mt-1 list-disc ps-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Tabs: the shared design, sending, then one per kind of email. --}}
    <div class="mb-4 flex flex-wrap gap-2">
        <button type="button" wire:click="openTab('design')" class="rounded-lg px-3 py-1.5 text-sm font-medium ring-1 transition {{ $tabClass($tab === 'design') }}">{{ __('Design & signature') }}</button>
        <button type="button" wire:click="openTab('delivery')" class="rounded-lg px-3 py-1.5 text-sm font-medium ring-1 transition {{ $tabClass($tab === 'delivery') }}">{{ __('Sending') }}</button>
        <span class="mx-1 hidden w-px bg-chrome-200 sm:block"></span>
        @foreach ($kinds as $key => $kind)
            <button type="button" wire:click="openTab('{{ $key }}')" class="rounded-lg px-3 py-1.5 text-sm font-medium ring-1 transition {{ $tabClass($tab === $key) }}">{{ __($kind['label']) }}</button>
        @endforeach
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- Editor --}}
        <div class="space-y-5 rounded-xl bg-white p-5 shadow-sm ring-1 ring-chrome-900/5">
            @if ($tab === 'design')
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-chrome-900">{{ __('Design & signature') }}</h2>
                        <p class="text-xs text-chrome-500">{{ __('Shared by every email. The logo is the one set under Settings → General.') }}</p>
                    </div>
                    <button type="button" wire:click="resetToDefault('design')" wire:confirm="{{ __('Put the design and signature back to the default?') }}" class="shrink-0 text-xs font-medium text-chrome-500 underline hover:text-chrome-800">{{ __('Reset to default') }}</button>
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    <label class="block text-sm">
                        <span class="font-medium text-chrome-800">{{ __('Accent colour') }}</span>
                        <span class="mt-1 flex items-center gap-2">
                            <input type="color" wire:model.live="layout.accent" class="h-9 w-12 cursor-pointer rounded border border-chrome-200 bg-white p-0.5">
                            <input type="text" wire:model.live.debounce.500ms="layout.accent" class="o-input font-mono text-xs" dir="ltr" maxlength="7">
                        </span>
                    </label>
                    <label class="block text-sm">
                        <span class="font-medium text-chrome-800">{{ __('Footer colour') }}</span>
                        <span class="mt-1 flex items-center gap-2">
                            <input type="color" wire:model.live="layout.footer_bg" class="h-9 w-12 cursor-pointer rounded border border-chrome-200 bg-white p-0.5">
                            <input type="text" wire:model.live.debounce.500ms="layout.footer_bg" class="o-input font-mono text-xs" dir="ltr" maxlength="7">
                        </span>
                    </label>
                    <label class="block text-sm">
                        <span class="font-medium text-chrome-800">{{ __('Header') }}</span>
                        <select wire:model.live="layout.show_logo" class="o-input mt-1">
                            <option value="1">{{ __('Company logo') }}</option>
                            <option value="0">{{ __('Company name only') }}</option>
                        </select>
                    </label>
                </div>

                @foreach ([
                    'greeting' => [__('Greeting'), __('The first line, e.g. "Dear {customer},". Leave the box empty to start without a greeting.'), 1],
                    'closing' => [__('Closing line'), __('Shown above the signature in every email.'), 2],
                    'signature' => [__('Signature'), __('One line per row. Wrap a word in **double stars** to make it bold.'), 5],
                    'footer' => [__('Footer'), __('The dark band at the bottom. One line per row; a line whose details are empty is left out.'), 4],
                    'small_print' => [__('Small print'), __('Optional: a confidentiality note or legal line under the footer.'), 3],
                ] as $field => [$label, $help, $rows])
                    <label class="block text-sm">
                        <span class="font-medium text-chrome-800">{{ $label }}</span>
                        <span class="mt-0.5 block text-xs text-chrome-500">{{ $help }}</span>
                        <textarea wire:model.live.debounce.500ms="layout.{{ $field }}" rows="{{ $rows }}" dir="ltr" class="o-input mt-1 font-mono text-sm"></textarea>
                    </label>
                @endforeach

                <div class="rounded-lg bg-chrome-50 p-3 text-xs text-chrome-600">
                    <p class="mb-1 font-medium text-chrome-800">{{ __('Details you can use in any email') }}</p>
                    {!! $chips(\App\Erp\Mail\EmailTemplates::COMMON) !!}
                    <p class="mt-2">{{ __('They are filled from Settings → General (company name, phone, website, address) and the customer on the record.') }}</p>
                </div>
            @elseif ($tab === 'delivery')
                <div>
                    <h2 class="text-base font-semibold text-chrome-900">{{ __('Sending') }}</h2>
                    <p class="text-xs text-chrome-500">{{ __('Whether emails go out on their own, and from which address.') }}</p>
                </div>

                <div class="flex items-start justify-between gap-4">
                    <div class="text-sm">
                        <p class="font-medium text-chrome-800">{{ __('Email documents to customers automatically') }}</p>
                        <p class="text-xs text-chrome-500">{{ __('New quotations, invoices, receipts and driver details are emailed to the customer as they are made.') }}</p>
                    </div>
                    <button type="button" wire:click="$toggle('enabled')" role="switch" aria-checked="{{ $enabled ? 'true' : 'false' }}"
                        class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition {{ $enabled ? 'bg-primary-500' : 'bg-chrome-300' }}">
                        <span class="inline-block size-4 transform rounded-full bg-white transition {{ $enabled ? 'translate-x-6 rtl:-translate-x-6' : 'translate-x-1 rtl:-translate-x-1' }}"></span>
                    </button>
                </div>

                <label class="block text-sm">
                    <span class="font-medium text-chrome-800">{{ __('Send from') }}</span>
                    <span class="mt-0.5 block text-xs text-chrome-500">{{ __('Customers see this address and their replies go to it. It must be a mailbox the mail server may send as.') }}</span>
                    <input type="email" wire:model.live.debounce.500ms="fromAddress" dir="ltr" class="o-input mt-1" placeholder="erp@example.com">
                    @error('fromAddress') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                </label>

                <label class="block text-sm">
                    <span class="font-medium text-chrome-800">{{ __('Terms and conditions') }}</span>
                    <span class="mt-0.5 block text-xs text-chrome-500">{{ __('Attached as a PDF to every automatic quotation, invoice and receipt. Leave empty to attach nothing. Markdown: # heading, - list, **bold**.') }}</span>
                    <textarea wire:model.live.debounce.800ms="terms" rows="10" dir="ltr" class="o-input mt-1 font-mono text-xs"></textarea>
                </label>
            @elseif (isset($kinds[$tab]))
                @php $kind = $kinds[$tab]; @endphp
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-chrome-900">{{ __($kind['label']) }}</h2>
                        <p class="text-xs text-chrome-500">{{ __($kind['description']) }}</p>
                    </div>
                    <button type="button" wire:click="resetToDefault('{{ $tab }}')" wire:confirm="{{ __('Put this email back to the default wording?') }}" class="shrink-0 text-xs font-medium text-chrome-500 underline hover:text-chrome-800">{{ __('Reset to default') }}</button>
                </div>

                @foreach ($templates->editableFields($tab) as $field)
                    @php
                        $extra = $kind['extras'][$field] ?? null;
                        $long = in_array($field, ['intro', 'outro'], true) || $extra !== null;
                    @endphp
                    @if ($extra !== null && $loop->index > 0 && ! isset($kind['extras'][$templates->editableFields($tab)[$loop->index - 1]]))
                        <p class="border-t border-chrome-100 pt-4 text-xs font-semibold uppercase tracking-wide text-chrome-500">{{ __('{payment_status} reads') }}</p>
                    @endif
                    <label class="block text-sm">
                        <span class="font-medium text-chrome-800">{{ $extra !== null ? __($extra['label']) : $fieldLabels[$field] }}</span>
                        @if ($long)
                            <textarea wire:model.live.debounce.500ms="types.{{ $tab }}.{{ $field }}" rows="{{ $extra !== null ? 2 : 4 }}" dir="ltr" class="o-input mt-1 text-sm"></textarea>
                        @else
                            <input type="text" wire:model.live.debounce.500ms="types.{{ $tab }}.{{ $field }}" dir="ltr" class="o-input mt-1">
                        @endif
                        @error('types.' . $tab . '.' . $field) <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                    </label>
                @endforeach

                <div class="rounded-lg bg-chrome-50 p-3 text-xs text-chrome-600">
                    <p class="mb-1 font-medium text-chrome-800">{{ __('Details you can use in this email') }}</p>
                    {!! $chips([...$kind['placeholders'], ...\App\Erp\Mail\EmailTemplates::COMMON]) !!}
                    <p class="mt-2">{{ __('A blank line starts a new paragraph. **Double stars** make a word bold. The details box, attachments, greeting and signature are added for you.') }}</p>
                </div>
            @endif
        </div>

        {{-- Live preview --}}
        <div class="lg:sticky lg:top-4 lg:self-start">
            <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-chrome-900/5">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-chrome-100 px-4 py-2.5">
                    <p class="text-sm font-semibold text-chrome-900">{{ __('Preview') }}</p>
                    <select wire:model.live="previewType" class="o-input w-auto py-1 text-sm" aria-label="{{ __('Email to preview') }}">
                        @foreach ($kinds as $key => $kind)
                            <option value="{{ $key }}">{{ __($kind['label']) }}</option>
                        @endforeach
                    </select>
                </div>
                <p class="border-b border-chrome-100 bg-chrome-50 px-4 py-2 text-xs text-chrome-600" dir="ltr">
                    <span class="font-semibold">Subject:</span>
                    {{ $previewSubject }}
                </p>
                <iframe title="{{ __('Preview') }}" srcdoc="{{ $previewHtml }}" class="block w-full border-0" style="height:75vh;min-height:520px;background:#f3f4f6;" sandbox=""></iframe>
            </div>

            <div class="mt-4 rounded-xl bg-white p-4 shadow-sm ring-1 ring-chrome-900/5">
                <p class="text-sm font-medium text-chrome-800">{{ __('Send a test email') }}</p>
                <p class="mb-2 text-xs text-chrome-500">{{ __('Saves your changes, then sends the email shown in the preview, filled with sample details.') }}</p>
                <div class="flex gap-2">
                    <input type="email" wire:model="testEmail" dir="ltr" class="o-input" placeholder="you@example.com">
                    <button type="button" wire:click="sendTest" wire:loading.attr="disabled" wire:target="sendTest" class="o-btn-primary shrink-0">
                        <span wire:loading.remove wire:target="sendTest">{{ __('Send test') }}</span>
                        <span wire:loading wire:target="sendTest">{{ __('Sending…') }}</span>
                    </button>
                </div>
                @error('testEmail') <span class="mt-1 block text-xs text-red-600">{{ $message }}</span> @enderror
                @if ($testSent !== '') <span class="mt-1 block text-xs text-emerald-700">{{ $testSent }}</span> @endif
            </div>
        </div>
    </div>
</div>
