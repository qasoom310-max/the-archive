<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * An email address the automated daily sales + stock PDF report is sent to.
 * Managed by an admin from the dashboard.
 *
 * @property int $id
 * @property string $email
 * @property bool $active
 */
final class ReportRecipient extends Model
{
    /** @var list<string> */
    protected $fillable = ['email', 'active'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * The active recipient email addresses, lower-cased and de-duplicated.
     *
     * @return list<string>
     */
    public static function activeEmails(): array
    {
        /** @var Collection<int, self> $rows */
        $rows = self::query()->where('active', true)->orderBy('email')->get();

        return array_values(array_unique($rows->map(
            static fn (self $r): string => strtolower($r->email),
        )->all()));
    }
}
