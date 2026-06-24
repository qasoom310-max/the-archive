<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A live one-time-code challenge for the admin 2FA step. One row per
 * (user, action); the code is stored as a SHA-256 hash and consumed (deleted)
 * on a successful verify. See {@see \App\Erp\Security\TwoFactorGate}.
 *
 * @property int $id
 * @property int $user_id
 * @property string $action
 * @property string $code_hash
 * @property \Illuminate\Support\Carbon $expires_at
 */
final class AdminOtpChallenge extends Model
{
    protected $table = 'admin_otp_challenges';

    /** @var list<string> */
    protected $fillable = ['user_id', 'action', 'code_hash', 'expires_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }
}
