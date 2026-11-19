<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Office;

use App\Http\Middleware\OfficeApi;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Models\JournalEntry;

/**
 * What the AI office may read, one business database at a time (chosen and
 * locked read-only by {@see OfficeApi}).
 *
 * Lists page with `after_id` (exclusive) and `limit` (1–500, default 200);
 * each page says the `next_after_id` to ask for, or null at the end. Dates are
 * `YYYY-MM-DD` in the company's own timezone — the one every timestamp here
 * is written in — and `to` includes that whole day.
 */
final class OfficeReadController
{
    private const DEFAULT_LIMIT = 200;

    private const MAX_LIMIT = 500;

    /** Which business this is, and the clock its times are in. */
    public function workspace(Request $request): JsonResponse
    {
        $workspace = $request->attributes->get(OfficeApi::WORKSPACE);
        if (! $workspace instanceof Workspace) {
            abort(500);
        }

        return response()->json([
            'id' => $workspace->id,
            'name' => $workspace->name,
            'timezone' => date_default_timezone_get(),
            'now' => Carbon::now()->format('Y-m-d H:i:s'),
            'accounting' => Schema::hasTable('journal_entries'),
        ]);
    }

    /** Everyone with an account here, so ids in other answers can be named. No emails, no passwords. */
    public function staff(): JsonResponse
    {
        $people = User::query()->orderBy('id')->get()->map(static fn (User $u): array => [
            'id' => $u->id,
            'name' => $u->name,
            'is_admin' => (bool) $u->is_admin,
            'is_accountant' => (bool) $u->is_accountant,
            'is_paused' => $u->isPaused(),
        ])->all();

        return response()->json(['data' => $people]);
    }

    /** The audit trail: who did what, and when. Never the IP address or what a password-vault entry was. */
    public function activity(Request $request): JsonResponse
    {
        $query = ActivityLog::query();
        $error = $this->filterDates($request, $query, 'created_at');
        if ($error !== null) {
            return $error;
        }

        $action = $request->query('action');
        if (is_string($action) && $action !== '') {
            $query->where('action', $action);
        }

        return $this->page($request, $query, static fn (ActivityLog $row): array => [
            'id' => $row->id,
            'at' => $row->created_at?->format('Y-m-d H:i:s'),
            'user_id' => $row->user_id,
            'user_name' => $row->user_name,
            'user_is_admin' => (bool) $row->user_is_admin,
            'action' => $row->action,
            'subject' => $row->subject,
            'subject_type' => $row->subject_type,
            'subject_id' => $row->subject_id,
            'description' => str_starts_with($row->action, 'vault_') ? null : $row->description,
        ]);
    }

    /** Journal entries by accounting date, with who made them and their totals. */
    public function journalEntries(Request $request): JsonResponse
    {
        if (! Schema::hasTable('journal_entries')) {
            return response()->json(['error' => 'Accounting is not installed in this workspace.'], 404);
        }

        $query = JournalEntry::query()
            ->withSum('items as total_debit', 'debit')
            ->withSum('items as total_credit', 'credit');

        $error = $this->filterDates($request, $query, 'date');
        if ($error !== null) {
            return $error;
        }

        $state = $request->query('state');
        if ($state !== null) {
            if (! in_array($state, ['draft', 'posted'], true)) {
                return self::invalid('state must be draft or posted.');
            }
            $query->where('state', $state);
        }

        /** @var array<int, string> $names */
        $names = User::query()->pluck('name', 'id')->all();

        return $this->page($request, $query, static function (JournalEntry $e) use ($names): array {
            $debit = round((float) ($e->getAttribute('total_debit') ?? 0), 3);
            $credit = round((float) ($e->getAttribute('total_credit') ?? 0), 3);
            $created = $e->getAttribute('created_at');

            return [
                'id' => $e->id,
                'number' => $e->number,
                'date' => $e->date->format('Y-m-d'),
                'reference' => $e->reference,
                'narration' => $e->narration,
                'state' => $e->state->value,
                'user_id' => $e->user_id,
                'user_name' => $e->user_id !== null ? ($names[$e->user_id] ?? null) : null,
                'created_at' => $created instanceof Carbon ? $created->format('Y-m-d H:i:s') : null,
                'posted_at' => $e->posted_at?->format('Y-m-d H:i:s'),
                'total_debit' => $debit,
                'total_credit' => $credit,
                'balanced' => abs($debit - $credit) < 0.0005,
            ];
        });
    }

    /**
     * @template TModel of Model
     * @param  Builder<TModel>  $query
     */
    private function filterDates(Request $request, Builder $query, string $column): ?JsonResponse
    {
        foreach (['from', 'to'] as $key) {
            $value = $request->query($key);
            if ($value === null) {
                continue;
            }
            if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
                return self::invalid($key . ' must be a date written YYYY-MM-DD.');
            }

            // By calendar day: a `date` column is stored with a midnight time
            // part, so a plain string compare would drop the `to` day itself.
            $query->whereDate($column, $key === 'from' ? '>=' : '<=', $value);
        }

        return null;
    }

    /**
     * @template TModel of Model
     * @param  Builder<TModel>  $query
     * @param  callable(TModel): array<string, mixed>  $shape
     */
    private function page(Request $request, Builder $query, callable $shape): JsonResponse
    {
        $after = $request->query('after_id', '0');
        $limit = $request->query('limit', (string) self::DEFAULT_LIMIT);
        if (! is_string($after) || ! ctype_digit($after) || ! is_string($limit) || ! ctype_digit($limit)
            || (int) $limit < 1 || (int) $limit > self::MAX_LIMIT) {
            return self::invalid('after_id must be a whole number and limit between 1 and ' . self::MAX_LIMIT . '.');
        }

        $table = $query->getModel()->getTable();
        $rows = $query->where($table . '.id', '>', (int) $after)
            ->orderBy($table . '.id')
            ->limit((int) $limit + 1)
            ->get();

        $more = $rows->count() > (int) $limit;
        $rows = $rows->take((int) $limit);
        $last = $rows->last();

        return response()->json([
            'data' => $rows->map($shape)->values()->all(),
            'next_after_id' => $more && $last instanceof Model ? (int) $last->getKey() : null,
        ]);
    }

    private static function invalid(string $message): JsonResponse
    {
        return response()->json(['error' => $message], 422);
    }
}
