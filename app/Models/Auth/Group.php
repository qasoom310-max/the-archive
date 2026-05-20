<?php

declare(strict_types=1);

namespace App\Models\Auth;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A security group / role. Mirrors Odoo's `res.groups`.
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string|null $description
 */
final class Group extends Model
{
    protected $table = 'res_groups';

    /** @var list<string> */
    protected $fillable = ['name', 'code', 'description'];

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'res_group_user', 'group_id', 'user_id');
    }

    /**
     * @return HasMany<ModelAccess, $this>
     */
    public function accesses(): HasMany
    {
        return $this->hasMany(ModelAccess::class, 'group_id');
    }
}
