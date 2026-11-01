<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Someone asking for their staff-app account to be deleted. The request only
 * records the wish: an admin deletes the account under Settings → Users, then
 * marks the request done.
 *
 * @property int $id
 * @property string $name
 * @property string $login           The email or username they sign in with
 * @property string|null $contact_email  Where to confirm, when the login is a username
 * @property string|null $note
 * @property string $source          web | app
 * @property int|null $user_id       The Main login, when asked from the app
 * @property string|null $business
 * @property string|null $ip
 * @property Carbon|null $handled_at
 * @property string|null $handled_by
 * @property Carbon|null $created_at
 */
final class AppDeletionRequest extends Model
{
    public const SOURCE_WEB = 'web';

    public const SOURCE_APP = 'app';

    protected $table = 'app_deletion_requests';

    /** @var list<string> */
    protected $fillable = ['name', 'login', 'contact_email', 'note', 'source', 'user_id', 'business', 'ip'];

    /** Always Main, wherever the request is made from — like the logins. */
    public function getConnectionName(): string
    {
        return Workspace::$landlordConnection;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'handled_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('handled_at');
    }
}
