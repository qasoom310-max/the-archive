<?php

declare(strict_types=1);

namespace App\Models\Mail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $icon
 * @property int $default_days
 * @property int $sequence
 */
final class MailActivityType extends Model
{
    protected $table = 'mail_activity_types';

    /** @var list<string> */
    protected $fillable = ['name', 'icon', 'default_days', 'sequence'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_days' => 'integer',
            'sequence' => 'integer',
        ];
    }

    /**
     * @return HasMany<MailActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(MailActivity::class);
    }
}
