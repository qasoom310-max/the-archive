<?php

declare(strict_types=1);

namespace App\Models\Pricing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * The widget's non-fare settings: where "book on WhatsApp" points, and how
 * much notice a trip needs. One row per database.
 *
 * @property int $id
 * @property string $whatsapp
 * @property int $lead_hours
 */
final class PricingSetting extends Model
{
    protected $table = 'pricing_settings';

    /** @var list<string> */
    protected $fillable = ['whatsapp', 'lead_hours'];

    /** @var array<string, mixed> */
    protected $attributes = ['whatsapp' => '', 'lead_hours' => 12];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['lead_hours' => 'integer'];
    }

    /** The one row, or a fresh unsaved instance carrying the defaults. */
    public static function current(): self
    {
        if (! Schema::hasTable('pricing_settings')) {
            return new self;
        }

        return self::query()->orderBy('id')->first() ?? new self;
    }
}
