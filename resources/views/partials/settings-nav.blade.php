@php
    /** @var string $active */
    $authUser = \Illuminate\Support\Facades\Auth::user();
    $isAdmin = $authUser instanceof \App\Models\User && $authUser->isAdmin();

    $installedModule = fn (string $name): bool => \App\Models\Ir\IrModule::query()
        ->where('name', $name)
        ->where('state', \App\Erp\Enums\ModuleState::Installed)
        ->exists();

    $sections = [
        ['key' => 'general', 'label' => 'General', 'url' => route('settings')],
    ];

    // Integration tabs are admin-only — the inner pages also `abort 403`, but
    // hiding the pill avoids a misleading entry point for cashiers.
    if ($isAdmin && $installedModule('whatsapp')) {
        $sections[] = ['key' => 'whatsapp', 'label' => 'WhatsApp', 'url' => url('/app/settings/whatsapp')];
    }

    if ($isAdmin && $installedModule('woocommerce')) {
        $sections[] = ['key' => 'woocommerce', 'label' => 'WooCommerce', 'url' => url('/app/settings/woocommerce')];
    }
@endphp

@if (count($sections) > 1)
    <div class="mb-5 flex flex-wrap gap-2">
        @foreach ($sections as $section)
            <a href="{{ $section['url'] }}" wire:navigate
                class="rounded-lg px-3 py-1.5 text-sm font-medium ring-1 transition
                    {{ $active === $section['key']
                        ? 'bg-primary-400 text-chrome-900 ring-primary-400'
                        : 'bg-white text-chrome-600 ring-chrome-200 hover:text-chrome-900' }}">
                {{ __($section['label']) }}
            </a>
        @endforeach
    </div>
@endif
