@php
    /** @var string $active */
    $whatsappInstalled = \App\Models\Ir\IrModule::query()
        ->where('name', 'whatsapp')
        ->where('state', \App\Erp\Enums\ModuleState::Installed)
        ->exists();

    $sections = [
        ['key' => 'general', 'label' => 'General', 'url' => route('settings')],
    ];

    if ($whatsappInstalled) {
        $sections[] = ['key' => 'whatsapp', 'label' => 'WhatsApp', 'url' => url('/app/settings/whatsapp')];
    }
@endphp

@if (count($sections) > 1)
    <div class="mb-5 flex flex-wrap gap-2">
        @foreach ($sections as $section)
            <a href="{{ $section['url'] }}" wire:navigate
                class="rounded-lg px-3 py-1.5 text-sm font-medium ring-1 transition
                    {{ $active === $section['key']
                        ? 'bg-primary-600 text-white ring-primary-600'
                        : 'bg-white text-chrome-600 ring-chrome-200 hover:text-chrome-900' }}">
                {{ $section['label'] }}
            </a>
        @endforeach
    </div>
@endif
