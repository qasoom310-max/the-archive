<?php

declare(strict_types=1);

namespace Modules\Storefront\Livewire;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Pos\Models\PosProduct;
use Modules\Storefront\Models\StorefrontProductDetail;

/**
 * Website details for each product: the description and fragrance notes in
 * English and Arabic, "featured" (shown on the home page) and the order in
 * the shop. Name, price, photos, category and stock still come from the
 * product itself — one place to change them, and the website follows.
 */
#[Layout('components.layouts.app')]
#[Title('Website products')]
final class StorefrontProducts extends Component
{
    public string $search = '';

    public ?int $editingId = null;

    /** @var array<string, array<string, string>> field => [en, ar] */
    public array $texts = [];

    public bool $featured = false;

    public int $sortOrder = 0;

    public bool $saved = false;

    public function mount(): void
    {
        $this->guard(Permission::Read);
    }

    private function guard(Permission $permission): void
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.product', $permission);
    }

    public function edit(int $productId): void
    {
        $this->guard(Permission::Read);
        $this->saved = false;

        $detail = StorefrontProductDetail::query()->where('pos_product_id', $productId)->first();

        $this->editingId = $productId;
        $this->texts = [];
        foreach (StorefrontProductDetail::TEXT_FIELDS as $field) {
            foreach (StorefrontProductDetail::LOCALES as $locale) {
                $this->texts[$field][$locale] = $detail?->text($field, $locale) ?? '';
            }
        }
        $this->featured = $detail !== null && $detail->featured;
        $this->sortOrder = $detail !== null ? $detail->sort_order : 0;
    }

    public function close(): void
    {
        $this->editingId = null;
    }

    public function save(): void
    {
        $this->guard(Permission::Write);

        if ($this->editingId === null || ! PosProduct::query()->whereKey($this->editingId)->exists()) {
            return;
        }

        $this->validate([
            'texts.description.*' => ['nullable', 'string', 'max:3000'],
            'texts.notes_top.*' => ['nullable', 'string', 'max:500'],
            'texts.notes_heart.*' => ['nullable', 'string', 'max:500'],
            'texts.notes_base.*' => ['nullable', 'string', 'max:500'],
            'sortOrder' => ['integer', 'between:-9999,9999'],
        ]);

        $values = [];
        foreach (StorefrontProductDetail::TEXT_FIELDS as $field) {
            $pair = [];
            foreach (StorefrontProductDetail::LOCALES as $locale) {
                $pair[$locale] = trim((string) ($this->texts[$field][$locale] ?? ''));
            }
            $values[$field] = $pair;
        }

        StorefrontProductDetail::query()->updateOrCreate(
            ['pos_product_id' => $this->editingId],
            [...$values, 'featured' => $this->featured, 'sort_order' => $this->sortOrder],
        );

        $this->saved = true;
    }

    public function render(): View
    {
        $term = trim($this->search);

        $products = PosProduct::query()
            ->with('category')
            ->where('active', true)
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%' . $term . '%')->orWhere('barcode', 'like', '%' . $term . '%')))
            ->orderBy('name')
            ->limit(300)
            ->get();

        $details = StorefrontProductDetail::query()
            ->whereIn('pos_product_id', $products->pluck('id'))
            ->get()
            ->keyBy('pos_product_id');

        return view('storefront::products', [
            'products' => $products,
            'details' => $details,
        ]);
    }
}
