<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int                   $id
 * @property int                   $user_id
 * @property string                $model_key
 * @property array<int, string>    $hidden_columns
 * @property array<int, string>    $column_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class UserViewPreference extends Model
{
    /** @var list<string> */
    protected $fillable = ['user_id', 'model_key', 'hidden_columns', 'column_order'];

    /** @var array<string, string> */
    protected $casts = [
        'hidden_columns' => 'array',
        'column_order' => 'array',
    ];

    /**
     * Get-or-create the row for this (user, model). Returns a fresh
     * unsaved instance with empty arrays when there's no row yet — the
     * caller decides whether persisting is warranted.
     */
    public static function forUserAndModel(int $userId, string $modelKey): self
    {
        $pref = self::query()
            ->where('user_id', $userId)
            ->where('model_key', $modelKey)
            ->first();

        if ($pref !== null) {
            return $pref;
        }

        $fresh = new self();
        $fresh->user_id = $userId;
        $fresh->model_key = $modelKey;
        $fresh->hidden_columns = [];
        $fresh->column_order = [];

        return $fresh;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
