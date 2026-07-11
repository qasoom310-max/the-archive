<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Auth\Group;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property string|null $avatar_path   Path on the `public` disk (nullable)
 * @property string|null $new_email     Pending email change awaiting verification
 * @property string|null $language      Personal language preference (`en`|`ar`); null = follow company.language
 * @property float|null $hourly_cost    Labour rate per hour (Project module); null = use project.default_hourly_cost
 * @property bool $is_admin
 * @property bool $is_super_admin   Owner tier above admin (a strict superset of is_admin)
 * @property bool $is_accountant    May confirm payments (with super-admins); not even a regular admin can
 * @property int|null $home_workspace_id  Locked to this workspace (database); null = unrestricted
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
        'hourly_cost',
        'is_admin',
        'is_super_admin',
        'is_accountant',
        'home_workspace_id',
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
            'is_super_admin' => 'boolean',
            'is_accountant' => 'boolean',
            // Project module: the column is added by that module's migration,
            // so it's simply absent (reads null) until Project is installed.
            'hourly_cost' => 'float',
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
     * The owner tier above admin. A super admin is a strict superset — its
     * row also carries `is_admin = true`, so `isAdmin()` is true too — but
     * `is_super_admin` gates owner-only powers (the dashboard system cards,
     * the company business type, promoting other super admins) and exempts
     * them from the regular-admin 2FA step.
     */
    public function isSuperAdmin(): bool
    {
        // The column is added by a core migration; guard so a not-yet-migrated
        // DB (or an old session payload) reads false rather than throwing.
        return ($this->getAttribute('is_super_admin') ?? false) === true;
    }

    /** Whether this user holds the Accountant role (column-guarded like above). */
    public function isAccountant(): bool
    {
        return ($this->getAttribute('is_accountant') ?? false) === true;
    }

    /**
     * The workspace (database) this user is LOCKED to, or null when
     * unrestricted. Column-guarded so a not-yet-migrated DB reads null.
     */
    public function homeWorkspaceId(): ?int
    {
        $value = $this->getAttribute('home_workspace_id');

        return $value === null ? null : (int) $value;
    }

    /**
     * A locked user may only ever operate in their home workspace: the tenancy
     * layer forces them there, the database switcher is hidden, and switching
     * or managing databases is refused.
     */
    public function isLockedToWorkspace(): bool
    {
        return $this->homeWorkspaceId() !== null;
    }

    /**
     * Who may confirm that a payment was actually received: the Accountant and
     * super-admins only — deliberately NOT regular admins.
     */
    public function canConfirmPayments(): bool
    {
        return $this->isSuperAdmin() || $this->isAccountant();
    }

    /**
     * Who may authorise (approve / decline) maintenance work orders before any
     * work or spend happens — a fleet manager, i.e. an admin or super-admin.
     * Regular staff can raise a request but not authorise it.
     */
    public function canApproveMaintenance(): bool
    {
        return $this->isAdmin() || $this->isSuperAdmin();
    }

    /**
     * Public URL of the user's avatar — null when no avatar is set, AND
     * null when the column points at a file that no longer exists on
     * disk (so the UI's fallback initial-letter renders instead of a
     * broken-image icon). Prevents the regression where a deploy wiped
     * the avatars bucket but the DB row kept the stale path; both call
     * sites (profile page + topbar) now go through this guard.
     */
    public function avatarUrl(): ?string
    {
        $path = $this->avatar_path;

        // Avatars sit on one shared disk, but every database has its own
        // `users` row. A workspace copy of this account (matched by email) is
        // created without the avatar_path the user set on Main — so fall back
        // to the Main (landlord) record, keeping the same profile image in
        // every workspace instead of showing a broken/empty avatar there.
        if (($path === null || $path === '') && is_string($this->email) && $this->email !== '') {
            $path = $this->landlordAvatarPath();
        }

        if ($path === null || $path === '') {
            return null;
        }

        $disk = Storage::disk('public');
        if (! $disk->exists($path)) {
            return null;
        }

        return $disk->url($path);
    }

    /**
     * The avatar path stored on the Main (landlord) copy of this account,
     * used only when the active workspace's row has none. Returns null when
     * already on Main (nothing to fall back to) or if the lookup fails.
     */
    private function landlordAvatarPath(): ?string
    {
        $landlord = Workspace::$landlordConnection;

        // On Main, the landlord IS the active default connection — no fallback.
        if (DB::getDefaultConnection() === $landlord) {
            return null;
        }

        try {
            $main = self::on($landlord)->where('email', $this->email)->first();
        } catch (Throwable) {
            return null; // landlord/workspaces unavailable → no fallback
        }

        $path = $main?->getAttribute('avatar_path');

        return is_string($path) && $path !== '' ? $path : null;
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
        if ($this->isSuperAdmin()) {
            return 'Super administrator';
        }

        if ($this->isAdmin()) {
            return 'Administrator';
        }

        $group = $this->groups()->first();

        return $group !== null ? $group->name : 'User';
    }
}
