<?php

declare(strict_types=1);

namespace Modules\Pos\Models;

use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A POS product category. Self-nesting via `parent_id` (a logical ref —
 * no DB FK). Cycle-safe: a category can never be parented to itself or
 * one of its descendants. `slug` is auto-derived (unique) from `name`.
 *
 * @property int $id
 * @property string $name
 * @property int|null $parent_id
 * @property string|null $slug
 * @property string|null $image
 * @property int $sequence
 */
final class PosCategory extends Model implements DefinesIrModel
{
    protected $table = 'pos_categories';

    /** @var list<string> */
    protected $fillable = ['name', 'parent_id', 'slug', 'image', 'sequence'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['sequence' => 'integer', 'parent_id' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (PosCategory $category): void {
            // Cycle guard: a node cannot sit under itself or a descendant.
            if ($category->parent_id !== null
                && $category->id !== null
                && in_array($category->parent_id, $category->subtreeIds(), true)) {
                $category->parent_id = null;
            }

            if ($category->slug === null || $category->slug === '') {
                $category->slug = $category->uniqueSlug(Str::slug((string) $category->name));
            }
        });
    }

    /**
     * @return BelongsTo<PosCategory, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<PosCategory, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sequence')->orderBy('name');
    }

    /**
     * @return HasMany<PosProduct, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(PosProduct::class, 'pos_category_id');
    }

    /**
     * This category's id plus every descendant's — used to filter the
     * POS grid to a whole subtree. Iterative + visited-set so malformed
     * data can never infinite-loop.
     *
     * @return list<int>
     */
    public function subtreeIds(): array
    {
        if ($this->id === null) {
            return [];
        }

        /** @var array<int, list<int>> $childrenOf */
        $childrenOf = [];
        foreach (self::query()->get(['id', 'parent_id']) as $row) {
            $childrenOf[$row->parent_id ?? 0][] = $row->id;
        }

        $ids = [];
        $stack = [$this->id];
        $seen = [];

        while ($stack !== []) {
            $current = array_pop($stack);

            if ($current === null || isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;
            $ids[] = $current;

            foreach ($childrenOf[$current] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return $ids;
    }

    private function uniqueSlug(string $base): string
    {
        $base = $base !== '' ? $base : 'category';
        $slug = $base;
        $n = 1;

        while (
            self::query()
                ->where('slug', $slug)
                ->when($this->id !== null, fn (Builder $q): Builder => $q->whereKeyNot($this->id))
                ->exists()
        ) {
            $slug = $base . '-' . (++$n);
        }

        return $slug;
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'pos.category',
            name: 'POS Category',
            class: self::class,
            table: 'pos_categories',
            module: 'pos',
            fields: [
                new FieldDefinition('name', 'Name', 'char', required: true, sequence: 10),
                new FieldDefinition('parent_id', 'Parent', 'many2one', relation: 'pos.category', sequence: 20),
                new FieldDefinition('sequence', 'Sequence', 'integer', sequence: 30),
            ],
            views: [
                new ViewDefinition('POS Categories', 'list', [
                    'columns' => [
                        ['field' => 'name', 'label' => 'Name', 'sortable' => true],
                        ['field' => 'slug', 'label' => 'Slug'],
                        ['field' => 'sequence', 'label' => 'Sequence', 'format' => 'number', 'align' => 'right', 'sortable' => true],
                    ],
                    'default_sort' => [['field' => 'sequence', 'dir' => 'asc']],
                    'per_page' => 20,
                    'open' => '/app/pos/category/{id}',
                ]),
                new ViewDefinition('POS Category', 'form', [
                    'cols' => 2,
                    'fields' => [
                        ['field' => 'name', 'label' => 'Name', 'widget' => 'text', 'required' => true],
                        [
                            'field' => 'parent_id',
                            'label' => 'Parent category',
                            'widget' => 'select',
                            'optionsFrom' => [
                                'model' => self::class,
                                'value' => 'id',
                                'label' => 'name',
                                'orderBy' => 'name',
                                'excludeSelf' => true,
                            ],
                            'help' => 'Leave blank for a top-level category.',
                        ],
                        ['field' => 'image', 'label' => 'Icon / image', 'widget' => 'text', 'placeholder' => 'emoji or image path', 'help' => 'Shown on the POS category button.'],
                        ['field' => 'sequence', 'label' => 'Sequence', 'widget' => 'number'],
                    ],
                ]),
            ],
        );
    }
}
