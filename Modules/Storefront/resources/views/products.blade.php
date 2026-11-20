@php
    $money = fn ($v) => \App\Erp\Money\Currencies::format($v);
    $missingLabel = ['name' => __('name'), 'category' => __('category'), 'price' => __('price'), 'photo' => __('photo')];
    $fieldLabels = [
        'description' => __('Description'),
        'notes_top' => __('Top notes'),
        'notes_heart' => __('Heart notes'),
        'notes_base' => __('Base notes'),
    ];
@endphp

<div class="mx-auto max-w-5xl p-4 sm:p-6">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-chrome-900">{{ __('Website products') }}</h1>
            <p class="text-sm text-chrome-500">{{ __('Description and fragrance notes for the online store. Name, price, photos, category and stock come from the product itself.') }}</p>
        </div>
        <a href="{{ url('/app/pos/website-orders') }}" wire:navigate class="o-btn-ghost">{{ __('Website orders') }}</a>
    </div>

    <input type="search" wire:model.live.debounce.300ms="search" class="o-input mb-4 w-full sm:w-72" placeholder="{{ __('Search products…') }}">

    <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-chrome-900/[0.06]">
        <table class="w-full text-sm">
            <thead class="bg-chrome-50 text-xs uppercase tracking-wide text-chrome-500">
                <tr>
                    <th class="px-3 py-2 text-start">{{ __('Product') }}</th>
                    <th class="hidden px-3 py-2 text-start sm:table-cell">{{ __('Category') }}</th>
                    <th class="px-3 py-2 text-end">{{ __('Price') }}</th>
                    <th class="px-3 py-2 text-start">{{ __('On the website') }}</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-chrome-100">
                @forelse ($products as $product)
                    @php
                        $detail = $details->get($product->id);
                        $missing = $product->missingForOnlineStore();
                        $hasText = $detail !== null && ($detail->text('description', 'en') !== '' || $detail->text('description', 'ar') !== '');
                    @endphp
                    <tr wire:key="sp-{{ $product->id }}" class="{{ $editingId === $product->id ? 'bg-primary-50/40' : '' }}">
                        <td class="px-3 py-2">
                            <div class="flex items-center gap-2">
                                @if ($product->image_path)
                                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($product->image_path) }}" alt="" class="size-9 rounded-lg object-cover ring-1 ring-chrome-200">
                                @else
                                    <span class="grid size-9 place-items-center rounded-lg bg-chrome-100 text-[10px] text-chrome-400">{{ __('No photo') }}</span>
                                @endif
                                <div class="min-w-0">
                                    <a href="{{ url('/app/pos/product/' . $product->id) }}" wire:navigate class="font-medium text-chrome-900 hover:underline">{{ $product->name }}</a>
                                    @if ($detail?->featured)
                                        <span class="ms-1 rounded-full bg-primary-100 px-1.5 text-[10px] font-semibold text-chrome-800">{{ __('Featured') }}</span>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td class="hidden px-3 py-2 text-chrome-600 sm:table-cell">{{ $product->category?->name }}</td>
                        <td class="px-3 py-2 text-end text-chrome-700">{{ $money($product->price) }}</td>
                        <td class="px-3 py-2 text-xs">
                            @if ($product->publishesOnline())
                                <span class="text-emerald-700">{{ __('Shown') }}</span>
                                @unless ($hasText)
                                    <span class="block text-amber-600">{{ __('No description yet') }}</span>
                                @endunless
                            @elseif ($missing !== [])
                                <span class="text-red-600">{{ __('Hidden — missing :list', ['list' => implode(', ', array_map(fn ($m) => $missingLabel[$m] ?? $m, $missing))]) }}</span>
                            @else
                                <span class="text-chrome-400">{{ __('Hidden by hand') }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-end">
                            <button type="button" wire:click="edit({{ $product->id }})" class="o-btn-ghost text-xs">{{ __('Website details') }}</button>
                        </td>
                    </tr>
                    @if ($editingId === $product->id)
                        <tr wire:key="sp-edit-{{ $product->id }}">
                            <td colspan="5" class="bg-chrome-50/60 px-3 py-4">
                                @if ($saved)
                                    <p class="mb-3 rounded-xl bg-emerald-50 px-4 py-2 text-sm text-emerald-800 ring-1 ring-emerald-200">{{ __('Saved. The website shows it at its next refresh.') }}</p>
                                @endif
                                <div class="grid gap-4 sm:grid-cols-2">
                                    @foreach ($fieldLabels as $field => $label)
                                        @foreach (['en' => 'English', 'ar' => 'العربية'] as $locale => $langLabel)
                                            <label class="block">
                                                <span class="mb-1 block text-xs font-medium text-chrome-600">{{ $label }} · {{ $langLabel }}</span>
                                                @if ($field === 'description')
                                                    <textarea wire:model="texts.{{ $field }}.{{ $locale }}" rows="3" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}" class="o-input w-full text-sm"></textarea>
                                                @else
                                                    <input type="text" wire:model="texts.{{ $field }}.{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}" class="o-input w-full text-sm">
                                                @endif
                                                @error('texts.' . $field . '.' . $locale) <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                            </label>
                                        @endforeach
                                    @endforeach
                                </div>
                                <p class="mt-2 text-xs text-chrome-500">{{ __('Separate notes with commas, for example: Saffron, Cardamom') }}</p>
                                <div class="mt-4 flex flex-wrap items-center gap-4">
                                    <label class="flex items-center gap-2 text-sm text-chrome-700">
                                        <input type="checkbox" wire:model="featured" class="rounded border-chrome-300 text-primary-500 focus:ring-primary-500">
                                        {{ __('Featured on the home page') }}
                                    </label>
                                    <label class="flex items-center gap-2 text-sm text-chrome-700">
                                        {{ __('Order in the shop') }}
                                        <input type="number" wire:model="sortOrder" class="o-input w-20 text-sm">
                                    </label>
                                    <span class="flex-1"></span>
                                    <button type="button" wire:click="close" class="o-btn-ghost text-sm">{{ __('Close') }}</button>
                                    <button type="button" wire:click="save" class="o-btn-primary text-sm">{{ __('Save') }}</button>
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="5" class="px-3 py-8 text-center text-chrome-400">{{ __('No products found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
