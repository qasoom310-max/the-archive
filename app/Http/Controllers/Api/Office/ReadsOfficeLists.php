<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Office;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * How the office API lists things: `after_id` (exclusive) + `limit` (1–500, default
 * 200) paging with `next_after_id`, and `from`/`to` filters by calendar day.
 */
trait ReadsOfficeLists
{
    private const DEFAULT_LIMIT = 200;

    private const MAX_LIMIT = 500;

    /**
     * @template TModel of Model
     * @param  Builder<TModel>  $query
     */
    protected function filterDates(Request $request, Builder $query, string $column): ?JsonResponse
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
    protected function page(Request $request, Builder $query, callable $shape): JsonResponse
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

    protected static function invalid(string $message): JsonResponse
    {
        return response()->json(['error' => $message], 422);
    }
}
