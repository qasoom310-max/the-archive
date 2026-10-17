<?php

declare(strict_types=1);

namespace App\Models\Pricing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * The widget's non-fare settings: where "book on WhatsApp" points, how much
 * notice a trip needs, and the rules of the cross-border empty-car offer.
 * One row per database.
 *
 * @property int $id
 * @property string $whatsapp
 * @property int $lead_hours
 * @property bool $border_enabled
 * @property float $border_discount_percent
 * @property float $border_travel_hours
 * @property int $border_wait_hours
 * @property int $border_notice_hours
 */
final class PricingSetting extends Model
{
    protected $table = 'pricing_settings';

    /** @var list<string> */
    protected $fillable = [
        'whatsapp', 'lead_hours',
        'border_enabled', 'border_discount_percent', 'border_travel_hours', 'border_wait_hours', 'border_notice_hours',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'whatsapp' => '',
        'lead_hours' => 12,
        'border_enabled' => true,
        'border_discount_percent' => 30,
        'border_travel_hours' => 2,
        'border_wait_hours' => 4,
        'border_notice_hours' => 2,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lead_hours' => 'integer',
            'border_enabled' => 'boolean',
            'border_discount_percent' => 'float',
            'border_travel_hours' => 'float',
            'border_wait_hours' => 'integer',
            'border_notice_hours' => 'integer',
        ];
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
