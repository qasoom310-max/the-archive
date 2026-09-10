<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Erp\Exceptions\RecordInUseException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Refuse to delete a record that other rows still point at.
 *
 * Shared masters — customers, drivers, cars — are referenced from several
 * modules, and not every reference is a real foreign key (the limousine tables
 * lost theirs when customers were merged into one shared list). So the check
 * is done here, by name: the model lists the tables and columns that may hold
 * its id, and `deleting` throws {@see RecordInUseException} if any do. The
 * tables are looked up only when they exist, because a workspace may not have
 * every module installed.
 */
trait GuardsDeletionWhenReferenced
{
    public static function bootGuardsDeletionWhenReferenced(): void
    {
        static::deleting(static function (Model $model): void {
            if (! $model instanceof self) {
                return;
            }

            $uses = $model->referencesInUse();
            if ($uses !== []) {
                throw RecordInUseException::for($model, $uses);
            }
        });
    }

    /**
     * Where this model's id may be stored: table => [columns, human label].
     *
     * @return array<string, array{0: list<string>, 1: string}>
     */
    abstract protected static function deletionReferences(): array;

    /**
     * How many rows still point at this record, by label. Empty when it is
     * free to go.
     *
     * @return array<string, int>
     */
    public function referencesInUse(): array
    {
        $id = $this->getKey();
        if ($id === null) {
            return [];
        }

        $out = [];
        foreach (static::deletionReferences() as $table => [$columns, $label]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $count = DB::table($table)
                ->where(static function (Builder $q) use ($columns, $id): void {
                    foreach ($columns as $column) {
                        $q->orWhere($column, $id);
                    }
                })
                ->count();

            if ($count > 0) {
                $out[$label] = ($out[$label] ?? 0) + $count;
            }
        }

        return $out;
    }
}
