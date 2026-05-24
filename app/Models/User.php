<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Auth\Group;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property string|null $avatar_path   Path on the `public` disk (nullable)
 * @property string|null $new_email     Pending email change awaiting verification
 * @property string|null $language      Personal language preference (`en`|`ar`); null = follow company.language
 * @property bool $is_admin
 * @property string $password
 */
final class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;
    use Notifiable;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'avatar_path',
        'new_email',
        'language',
        'is_admin',
        'password',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Group, $this>
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'res_group_user', 'user_id', 'group_id');
    }

    public function isAdmin(): bool
    {
        return $this->is_admin === true;
    }

    /**
     * Human-readable role label for the read-only field on the profile
     * page. "Administrator" for super-admins; otherwise either the
     * user's first assigned group name (e.g. "POS / User") or a generic
     * "User" if they have no groups. Editing roles happens via the
     * admin User Resource, not from here — this method is display-only.
     */
    public function roleLabel(): string
    {
        if ($this->isAdmin()) {
            return 'Administrator';
        }

        $group = $this->groups()->first();

        return $group !== null ? $group->name : 'User';
    }
}
