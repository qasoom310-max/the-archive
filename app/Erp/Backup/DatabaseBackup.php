<?php

declare(strict_types=1);

namespace App\Erp\Backup;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Whole-database snapshot + restore — an in-app "Hostinger backup" for the
 * ACTIVE database. A snapshot is a gzipped JSON dump of every business table;
 * a restore rolls the whole database back to that snapshot (a point-in-time
 * rollback, not a per-record undo). Engine-agnostic (works for the MySQL Main
 * DB and the SQLite tenant workspaces alike) via the query builder.
 *
 * Snapshots are files under `storage/app/backups/<db-key>/` — NOT a database
 * table — so a restore (which only rewrites DB rows) never disturbs the backup
 * catalogue itself. Each database's backups live in their own folder keyed by
 * the connection's database name, so tenants can't see each other's.
 */
final class DatabaseBackup
{
    /** Days a snapshot is kept before the daily sweep deletes it. */
    public const RETENTION_DAYS = 14;

    private const ROOT = 'backups';

    /**
     * Transient / infrastructure tables that are pointless (or harmful) to
     * snapshot and restore: framework plumbing and the schema version. Excluding
     * `sessions` also means a restore never logs the admin out mid-operation.
     *
     * @var list<string>
     */
    private const EXCLUDED = [
        'migrations', 'sessions', 'cache', 'cache_locks',
        'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens',
    ];

    private function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk('local');
    }

    /** The storage folder holding the active database's snapshots. */
    public function currentKey(): string
    {
        return substr(sha1((string) DB::connection()->getDatabaseName()), 0, 20);
    }

    /**
     * The business tables to snapshot (everything except the excluded infra
     * tables and any SQLite internals).
     *
     * @return list<string>
     */
    private function tables(): array
    {
        $tables = array_filter(
            Schema::getTableListing(),
            static fn (string $t): bool => ! str_starts_with($t, 'sqlite_') && ! in_array($t, self::EXCLUDED, true),
        );

        return array_values($tables);
    }

    /**
     * Snapshot the active database to a gzipped JSON file. Returns the storage
     * path (relative to the local disk).
     */
    public function snapshot(): string
    {
        $tables = [];
        foreach ($this->tables() as $table) {
            $tables[$table] = DB::table($table)->get()
                ->map(static fn ($row): array => (array) $row)
                ->all();
        }

        $document = [
            'meta' => [
                'database' => (string) DB::connection()->getDatabaseName(),
                'created_at' => now()->toIso8601String(),
                'tables' => count($tables),
            ],
            'tables' => $tables,
        ];

        $encoded = gzencode(json_encode($document, JSON_THROW_ON_ERROR), 6);
        // A short random suffix so two backups in the same second can't collide.
        $name = now()->format('Y-m-d_His') . '_' . \Illuminate\Support\Str::random(4) . '.json.gz';
        $path = self::ROOT . '/' . $this->currentKey() . '/' . $name;
        $this->disk()->put($path, $encoded !== false ? $encoded : '');

        return $path;
    }

    /**
     * All snapshots for the active database, newest first.
     *
     * @return list<array{path: string, name: string, size: int, created_at: Carbon}>
     */
    public function list(): array
    {
        $dir = self::ROOT . '/' . $this->currentKey();

        $out = [];
        foreach ($this->disk()->files($dir) as $file) {
            if (! str_ends_with($file, '.json.gz')) {
                continue;
            }
            $out[] = [
                'path' => $file,
                'name' => basename($file),
                'size' => $this->disk()->size($file),
                'created_at' => Carbon::createFromTimestamp($this->disk()->lastModified($file)),
            ];
        }

        usort($out, static fn (array $a, array $b): int => $b['created_at'] <=> $a['created_at']);

        return $out;
    }

    /** Whether a given path is a snapshot belonging to the active database. */
    public function owns(string $path): bool
    {
        return str_starts_with($path, self::ROOT . '/' . $this->currentKey() . '/')
            && str_ends_with($path, '.json.gz')
            && $this->disk()->exists($path);
    }

    /**
     * Roll the active database back to the given snapshot: every captured table
     * is emptied and repopulated from the dump, inside one transaction with
     * foreign-key checks off. Columns added by later migrations are left at
     * their default; columns dropped since are ignored. Infra tables are never
     * touched.
     */
    public function restore(string $path): void
    {
        $raw = $this->disk()->get($path);
        $json = $raw !== null ? gzdecode($raw) : false;
        if ($json === false) {
            throw new \RuntimeException('Backup file is unreadable or corrupt.');
        }

        /** @var array{tables?: array<string, list<array<string, mixed>>>} $document */
        $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $tables = $document['tables'] ?? [];

        Schema::disableForeignKeyConstraints();
        try {
            DB::transaction(function () use ($tables): void {
                foreach ($tables as $table => $rows) {
                    if (in_array($table, self::EXCLUDED, true) || ! Schema::hasTable($table)) {
                        continue;
                    }

                    $columns = array_flip(Schema::getColumnListing($table));
                    DB::table($table)->delete();

                    foreach (array_chunk($rows, 500) as $chunk) {
                        $clean = array_map(
                            static fn (array $row): array => array_intersect_key($row, $columns),
                            $chunk,
                        );
                        DB::table($table)->insert($clean);
                    }
                }
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    /** Delete a single snapshot of the active database (guarded by ownership). */
    public function delete(string $path): void
    {
        if ($this->owns($path)) {
            $this->disk()->delete($path);
        }
    }

    /**
     * Delete snapshots of the active database older than the retention window.
     * Returns how many were removed.
     */
    public function purge(int $days = self::RETENTION_DAYS): int
    {
        $cutoff = now()->subDays($days)->getTimestamp();
        $removed = 0;

        foreach ($this->disk()->files(self::ROOT . '/' . $this->currentKey()) as $file) {
            if (str_ends_with($file, '.json.gz') && $this->disk()->lastModified($file) < $cutoff) {
                $this->disk()->delete($file);
                $removed++;
            }
        }

        return $removed;
    }
}
