<?php

declare(strict_types=1);

namespace App\Erp\Office;

use App\Models\OfficeToken;
use Illuminate\Support\Carbon;

/**
 * Issues, finds and revokes the AI office's read-only keys. The plain key is
 * shown once, at issue; only its SHA-256 is kept, so a copy of the database
 * does not hand out working keys.
 */
final class OfficeTokens
{
    public const PREFIX = 'office_';

    /**
     * @param  list<int>  $workspaceIds
     * @return array{0: OfficeToken, 1: string}  the stored key and its plain text
     */
    public function issue(string $name, array $workspaceIds): array
    {
        $plain = self::PREFIX . bin2hex(random_bytes(32));

        $token = OfficeToken::query()->create([
            'name' => $name,
            'token_hash' => self::hash($plain),
            'workspace_ids' => array_values(array_unique(array_map('intval', $workspaceIds))),
        ]);

        return [$token, $plain];
    }

    /** The live key for this plain text, or null (unknown, malformed or revoked). */
    public function find(string $plain): ?OfficeToken
    {
        if (! str_starts_with($plain, self::PREFIX) || strlen($plain) > 200) {
            return null;
        }

        $token = OfficeToken::query()->where('token_hash', self::hash($plain))->first();

        return $token !== null && ! $token->isRevoked() ? $token : null;
    }

    public function revoke(OfficeToken $token): void
    {
        $token->forceFill(['revoked_at' => Carbon::now()])->save();
    }

    private static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
