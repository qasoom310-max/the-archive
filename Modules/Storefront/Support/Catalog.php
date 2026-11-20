<?php

declare(strict_types=1);

namespace Modules\Storefront\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Pos\Models\PosCategory;
use Modules\Pos\Models\PosProduct;
use Modules\Storefront\Models\StorefrontConfiguration;
use Modules\Storefront\Models\StorefrontProductDetail;
use Modules\Storefront\Services\StorefrontOrderIntake;

/**
 * What the website is allowed to know, built from the POS products.
 *
 * Only products that pass {@see PosProduct::publishesOnline()} are listed —
 * the same rule the WooCommerce push uses: active, "Show in the online store"
 * ticked, and a name, category, price and photo. Costs, suppliers, exact stock
 * counts and recipes never leave the ERP; the website gets `in_stock` only.
 */
final class Catalog
{
    /**
     * @return array{store: array<string, mixed>, categories: list<array<string, mixed>>, products: list<array<string, mixed>>}
     */
    public function build(): array
    {
        $details = StorefrontProductDetail::query()->get()->keyBy('pos_product_id');

        $products = PosProduct::query()
            ->with('category')
            ->where('active', true)
            ->orderBy('id')
            ->get()
            ->filter(static fn (PosProduct $p): bool => $p->publishesOnline())
            ->map(fn (PosProduct $p): array => $this->product($p, $details->get($p->id)))
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->values()
            ->all();

        $usedCategoryIds = array_values(array_unique(array_map(static fn (array $p): int => (int) $p['category_id'], $products)));

        $categories = PosCategory::query()
            ->whereIn('id', $usedCategoryIds)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->map(fn (PosCategory $c): array => [
                'id' => (int) $c->id,
                'slug' => $this->slug($c->getTranslation('name', 'en', false), (int) $c->id),
                'name' => $this->names($c),
                'image' => $this->url((string) $c->getAttribute('image')),
            ])
            ->values()
            ->all();

        return [
            'store' => $this->store(),
            'categories' => $categories,
            'products' => $products,
        ];
    }

    /**
     * One product as the website sees it. Public so the order intake can check
     * a basket against exactly what the website was shown.
     *
     * @return array<string, mixed>
     */
    public function product(PosProduct $product, ?StorefrontProductDetail $detail): array
    {
        $updated = $product->getAttribute('updated_at');
        $gallery = array_map(fn (string $path): ?string => $this->url($path), $product->galleryImages());

        $text = static fn (string $field): array => [
            'en' => $detail?->text($field, 'en') ?? '',
            'ar' => $detail?->text($field, 'ar') ?? '',
        ];
        $notes = static fn (string $field): array => [
            'en' => $detail?->notes($field, 'en') ?? [],
            'ar' => $detail?->notes($field, 'ar') ?? [],
        ];

        return [
            'id' => (int) $product->id,
            'sku' => (string) $product->barcode !== '' ? (string) $product->barcode : null,
            'slug' => $this->slug($product->getTranslation('name', 'en', false), (int) $product->id),
            'name' => $this->names($product),
            // What the customer pays for one, tax included — the same sum the
            // order intake and the register make.
            'price' => StorefrontOrderIntake::line($product, 1)['total'],
            'size_ml' => $product->bottle_size_ml !== null ? (float) $product->bottle_size_ml : null,
            'category_id' => (int) $product->pos_category_id,
            'in_stock' => (float) $product->stock_on_hand > 0,
            'image' => $this->url((string) $product->image_path),
            'gallery' => array_values(array_filter($gallery)),
            'description' => $text('description'),
            'notes' => [
                'top' => $notes('notes_top'),
                'heart' => $notes('notes_heart'),
                'base' => $notes('notes_base'),
            ],
            'featured' => $detail !== null && $detail->featured,
            'sort_order' => $detail !== null ? $detail->sort_order : 0,
            'updated_at' => $updated instanceof \DateTimeInterface ? $updated->format(DATE_ATOM) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function store(): array
    {
        $config = StorefrontConfiguration::current();

        return [
            'currency' => 'BHD',
            'whatsapp' => $config->whatsapp_number !== null && $config->whatsapp_number !== ''
                ? preg_replace('/\D+/', '', $config->whatsapp_number)
                : null,
            'delivery_charge' => round((float) $config->delivery_charge, 3),
            'free_delivery_over' => $config->free_delivery_over !== null ? round((float) $config->free_delivery_over, 3) : null,
        ];
    }

    /** @return array{en: string, ar: string} */
    private function names(PosProduct|PosCategory $model): array
    {
        $en = trim((string) $model->getTranslation('name', 'en', false));
        $ar = trim((string) $model->getTranslation('name', 'ar', false));
        $any = trim((string) $model->name);

        return ['en' => $en !== '' ? $en : $any, 'ar' => $ar !== '' ? $ar : ($en !== '' ? $en : $any)];
    }

    private function slug(string $name, int $id): string
    {
        $base = Str::slug($name);

        return ($base !== '' ? $base . '-' : '') . $id;
    }

    private function url(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }
}
