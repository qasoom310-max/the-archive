<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One saved login: a site, who signs in, the password, and a note.
 *
 * The password and the note are encrypted at rest with APP_KEY. That is the
 * same protection the WhatsApp and payment-gateway secrets already get, and it
 * is worth being clear about what it does and does not buy: a stolen database
 * file alone is useless, but the key lives on the same server, so anyone who
 * takes BOTH the database and the application files can read everything. It is
 * far better than a spreadsheet. It is not a zero-knowledge password manager.
 *
 * @property int $id
 * @property string $name
 * @property string|null $url
 * @property string|null $username
 * @property string|null $password
 * @property string|null $note
 * @property bool $owner_only
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property Carbon|null $updated_at
 */
final class VaultEntry extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'name', 'url', 'username', 'password', 'note', 'owner_only',
        'created_by', 'updated_by',
    ];

    /** A new entry is private until somebody widens it on purpose. */
    protected $attributes = ['owner_only' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // The note is encrypted as well as the password: it is where the
            // recovery codes and security answers end up, and those are worth
            // as much as the password they protect.
            'password' => 'encrypted',
            'note' => 'encrypted',
            'owner_only' => 'boolean',
        ];
    }

    /**
     * What this viewer is allowed to see.
     *
     * A regular admin sees only the entries somebody deliberately shared. This
     * is applied as a QUERY scope rather than a filter in the view, so an entry
     * they may not see never reaches the browser at all - not even encrypted,
     * not even as a row they could count.
     *
     * @param  Builder<VaultEntry>  $query
     * @return Builder<VaultEntry>
     */
    public function scopeVisibleTo(Builder $query, bool $isOwner): Builder
    {
        return $isOwner ? $query : $query->where('owner_only', false);
    }

    /** A link is only worth rendering as one if it actually goes somewhere. */
    public function linkUrl(): ?string
    {
        $url = trim((string) $this->url);

        if ($url === '') {
            return null;
        }

        return preg_match('~^https?://~i', $url) === 1 ? $url : 'https://'.$url;
    }

    /** What the list shows in place of the password. */
    public function hasPassword(): bool
    {
        return trim((string) $this->password) !== '';
    }
}
