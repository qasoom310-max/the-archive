<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Office;

use App\Http\Middleware\OfficeApi;
use App\Models\ActivityLog;
use App\Models\OfficeToken;
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
    use ReadsOfficeLists;

    /** The companies this key may read (the only call that names no company). */
    public function workspaces(Request $request): JsonResponse
    {
        $token = $request->attributes->get(OfficeApi::TOKEN);
        if (! $token instanceof OfficeToken) {
            abort(500);
        }

        $workspaces = Workspace::query()->whereIn('id', $token->workspace_ids)->orderByDesc('is_main')->orderBy('name')->get()
            ->map(static fn (Workspace $w): array => ['id' => $w->id, 'name' => $w->name, 'main' => (bool) $w->is_main])->all();

        return response()->json(['data' => $workspaces]);
    }

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
}
