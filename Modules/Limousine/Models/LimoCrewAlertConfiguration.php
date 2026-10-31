<?php

declare(strict_types=1);

namespace Modules\Limousine\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Limousine\Support\WhatsAppNumber;

/**
 * Single-row settings for the automatic driver-details messages
 * ({@see \Modules\Limousine\Services\CrewNotices}). Same shape as
 * {@see LimoPortalConfiguration}: `current()` always returns an instance, even
 * before anything is saved.
 *
 * @property int $id
 * @property bool $enabled
 * @property string|null $operations_numbers  One WhatsApp number per line (or comma-separated)
 * @property string|null $owner_number
 */
final class LimoCrewAlertConfiguration extends Model
{
    protected $table = 'limo_crew_alert_configuration';

    /** @var list<string> */
    protected $fillable = ['enabled', 'operations_numbers', 'owner_number'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrNew([]);
    }

    /**
     * The operations team's numbers, ready for WhatsApp (international digits),
     * without blanks or repeats.
     *
     * @return list<string>
     */
    public function operationsNumbers(): array
    {
        // Lines, commas or semicolons between numbers — never spaces, which
        // people type INSIDE a number ("+973 3300 0001").
        $parts = preg_split('/[\r\n,;]+/', (string) $this->operations_numbers) ?: [];

        $numbers = [];
        foreach ($parts as $part) {
            $number = WhatsAppNumber::normalize($part);
            if ($number !== '') {
                $numbers[$number] = $number;
            }
        }

        return array_values($numbers);
    }

    public function ownerNumber(): string
    {
        return WhatsAppNumber::normalize((string) $this->owner_number);
    }
}
