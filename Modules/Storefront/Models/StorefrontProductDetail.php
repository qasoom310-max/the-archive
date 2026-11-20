<?php

declare(strict_types=1);

namespace Modules\Storefront\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Pos\Models\PosProduct;

/**
 * What only the website needs to know about a POS product. Text fields are
 * stored as {"en": "...", "ar": "..."}; notes are comma-separated lists
 * ("Saffron, Cardamom").
 *
 * @property int $id
 * @property int $pos_product_id
 * @property array<string, string>|null $description
 * @property array<string, string>|null $notes_top
 * @property array<string, string>|null $notes_heart
 * @property array<string, string>|null $notes_base
 * @property bool $featured
 * @property int $sort_order
 */
final class StorefrontProductDetail extends Model
{
    public const TEXT_FIELDS = ['description', 'notes_top', 'notes_heart', 'notes_base'];

    public const LOCALES = ['en', 'ar'];

    protected $table = 'storefront_product_details';

    /** @var list<string> */
    protected $fillable = ['pos_product_id', 'description', 'notes_top', 'notes_heart', 'notes_base', 'featured', 'sort_order'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'description' => 'array',
            'notes_top' => 'array',
            'notes_heart' => 'array',
            'notes_base' => 'array',
            'featured' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<PosProduct, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(PosProduct::class, 'pos_product_id');
    }

    public function text(string $field, string $locale): string
    {
        /** @var mixed $value */
        $value = $this->getAttribute($field);

        return is_array($value) && is_string($value[$locale] ?? null) ? trim($value[$locale]) : '';
    }

    /**
     * A notes field as a clean list, split on commas (Arabic "،" too).
     *
     * @return list<string>
     */
    public function notes(string $field, string $locale): array
    {
        $parts = preg_split('/\s*[,،]\s*/u', $this->text($field, $locale)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));
    }
}
