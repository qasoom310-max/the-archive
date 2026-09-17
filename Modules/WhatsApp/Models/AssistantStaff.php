<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One WhatsApp number allowed to use the staff assistant, and the ERP user it
 * acts as. Everything the assistant does runs under that user's permissions.
 *
 * @property int $id
 * @property string $phone  Digits only, country code included (e.g. 97338467744)
 * @property int $user_id
 * @property bool $active
 * @property-read User|null $user
 */
final class AssistantStaff extends Model
{
    protected $table = 'whatsapp_assistant_staff';

    /** @var list<string> */
    protected $fillable = ['phone', 'user_id', 'active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['user_id' => 'integer', 'active' => 'boolean'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Meta sends ids as bare digits; store and compare the same way. */
    public static function normalise(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    public static function forNumber(string $waId): ?self
    {
        $digits = self::normalise($waId);
        if ($digits === '') {
            return null;
        }

        return self::query()->where('phone', $digits)->where('active', true)->first();
    }
}
