<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A switchable database ("workspace"). The Main workspace ({@see $is_main})
 * uses the app's default connection (today's data); every other workspace is
 * an isolated SQLite file at {@see $database}.
 *
 * Pinned to the `landlord` connection so the registry is always read from the
 * Main DB even while a tenant connection is the active default.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $database
 * @property bool $is_main
 * @property int|null $owner_user_id
 */
final class Workspace extends Model
{
    /**
     * Name of the boot-time default connection (the Main DB). Set once by
     * {@see \App\Providers\WorkspaceServiceProvider} before any tenant swap,
     * so the registry is always read from Main even while a tenant connection
     * is the active default. Pinning by NAME (not a clone) shares the same
     * connection/schema — required for the in-memory SQLite used in tests.
     */
    public static string $landlordConnection = 'sqlite';

    public function getConnectionName(): string
    {
        return self::$landlordConnection;
    }

    /** @var list<string> */
    protected $fillable = ['name', 'slug', 'database', 'is_main', 'owner_user_id'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_main' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
            'owner_user_id' => 'integer',
        ];
    }

    /**
     * Cookie key carrying the active workspace id across requests (read by
     * SetActiveWorkspace before the DB connection is swapped — DB-independent
     * on purpose, so it can be read before connecting).
     */
    public const COOKIE = 'erp_workspace';

    /**
     * Absolute path to the workspace's SQLite file (null for Main, which uses
     * the default connection). Stored as a bare filename so the row is
     * portable between local and prod paths.
     */
    public function databasePath(): ?string
    {
        return $this->database !== null
            ? storage_path('app/workspaces/' . $this->database)
            : null;
    }
}
