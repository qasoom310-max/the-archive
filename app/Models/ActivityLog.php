<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One immutable audit-trail entry. Written only through
 * {@see \App\Erp\Activity\ActivityLogger}; never updated.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $user_name
 * @property bool $user_is_admin
 * @property string $action
 * @property string|null $subject
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $description
 * @property string|null $ip_address
 * @property \Illuminate\Support\Carbon|null $created_at
 */
final class ActivityLog extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'user_id', 'user_name', 'user_is_admin',
        'action', 'subject', 'subject_type', 'subject_id', 'description', 'ip_address', 'created_at',
    ];

    /**
     * Action → user-facing label. Keys are the stored `action` codes; the
     * label is wrapped in `__()` at the call site so it localises.
     *
     * @var array<string, string>
     */
    public const LABELS = [
        'login' => 'Signed in',
        'logout' => 'Signed out',
        'logout_idle' => 'Signed out (inactive)',
        'login_failed' => 'Failed sign-in',
        'quoted' => 'Quoted',
        'created' => 'Created',
        'updated' => 'Updated',
        'deleted' => 'Deleted',
        'user_created' => 'Created user',
        'user_updated' => 'Updated user',
        'user_deleted' => 'Deleted user',
        'user_paused' => 'Paused user',
        'user_unpaused' => 'Unpaused user',
        'settings_updated' => 'Updated settings',
        'backup_created' => 'Created backup',
        'backup_restored' => 'Restored backup',
        // Record workflow actions (orders, work orders, …).
        'started' => 'Started',
        'returned' => 'Returned',
        'closed' => 'Closed',
        'cancelled' => 'Cancelled',
        'payment_confirmed' => 'Payment confirmed',
        'deposit_settled' => 'Deposit settled',
        'approved' => 'Approved',
        'declined' => 'Declined',
        'completed' => 'Completed',
        'invoiced' => 'Invoiced',
        'car_replaced' => 'Car replaced',
        'production_reversed' => 'Reversed production',
        'production_reopened' => 'Reopened production',
    ];

    /**
     * Action → Tailwind tone tokens for the badge (bg + text).
     *
     * @var array<string, string>
     */
    public const COLORS = [
        'login' => 'bg-emerald-100 text-emerald-700',
        'logout' => 'bg-chrome-100 text-chrome-600',
        'logout_idle' => 'bg-chrome-100 text-chrome-600',
        'quoted' => 'bg-sky-100 text-sky-700',
        'login_failed' => 'bg-red-100 text-red-700',
        'created' => 'bg-sky-100 text-sky-700',
        'updated' => 'bg-amber-100 text-amber-700',
        'deleted' => 'bg-red-100 text-red-700',
        'user_created' => 'bg-indigo-100 text-indigo-700',
        'user_updated' => 'bg-amber-100 text-amber-700',
        'user_deleted' => 'bg-red-100 text-red-700',
        'user_paused' => 'bg-amber-100 text-amber-700',
        'user_unpaused' => 'bg-emerald-100 text-emerald-700',
        'settings_updated' => 'bg-violet-100 text-violet-700',
        'backup_created' => 'bg-sky-100 text-sky-700',
        'backup_restored' => 'bg-amber-100 text-amber-700',
        'started' => 'bg-sky-100 text-sky-700',
        'returned' => 'bg-emerald-100 text-emerald-700',
        'closed' => 'bg-emerald-100 text-emerald-700',
        'cancelled' => 'bg-red-100 text-red-700',
        'payment_confirmed' => 'bg-emerald-100 text-emerald-700',
        'deposit_settled' => 'bg-emerald-100 text-emerald-700',
        'approved' => 'bg-emerald-100 text-emerald-700',
        'declined' => 'bg-red-100 text-red-700',
        'completed' => 'bg-emerald-100 text-emerald-700',
        'invoiced' => 'bg-indigo-100 text-indigo-700',
        'car_replaced' => 'bg-orange-100 text-orange-700',
        'production_reversed' => 'bg-red-100 text-red-700',
        'production_reopened' => 'bg-amber-100 text-amber-700',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_is_admin' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function actionLabel(): string
    {
        return __(self::LABELS[$this->action] ?? ucfirst(str_replace('_', ' ', $this->action)));
    }

    public function actionColor(): string
    {
        return self::COLORS[$this->action] ?? 'bg-chrome-100 text-chrome-600';
    }
}
