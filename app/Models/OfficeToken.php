<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A read-only key for the AI office app. Always on MAIN, like the sign-in
 * tokens, whatever database a request has entered. Issued and revoked only
 * from the command line ({@see \App\Erp\Office\OfficeTokens}).
 *
 * @property int $id
 * @property string $name
 * @property string $token_hash
 * @property list<int> $workspace_ids
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 */
final class OfficeToken extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'token_hash', 'workspace_ids', 'last_used_at', 'revoked_at'];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

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
            'workspace_ids' => 'array',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function mayRead(Workspace $workspace): bool
    {
        return in_array((int) $workspace->id, array_map('intval', $this->workspace_ids), true);
    }
}
