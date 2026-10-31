<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A phone the staff app is signed in on — where its notifications go.
 *
 * @property int $id
 * @property int $user_id   The account in THIS database
 * @property string $token  Expo push token ("ExponentPushToken[…]")
 * @property string|null $platform  ios | android
 * @property Carbon|null $last_seen_at
 */
final class AppDevice extends Model
{
    protected $table = 'app_devices';

    /** @var list<string> */
    protected $fillable = ['user_id', 'token', 'platform', 'last_seen_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * The push tokens of these accounts' phones.
     *
     * @param  list<int>  $userIds
     * @return list<string>
     */
    public static function tokensFor(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return self::query()->whereIn('user_id', $userIds)->pluck('token')
            ->map(static fn (mixed $token): string => (string) $token)
            ->unique()->values()->all();
    }
}
