<?php

declare(strict_types=1);

namespace App\Erp\Ai;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Records what each call to Claude cost, and adds it up for the settings page.
 *
 * The price comes from the tokens the API reports on the response and the
 * model's list price (Anthropic first-party rates, USD per million tokens).
 * Cache writes are 1.25× the input price and cache reads 0.1×. It is an
 * estimate of the bill, not the invoice: the Anthropic console is the record.
 * Recording never stops the call it measures.
 */
final class AiUsage
{
    /** USD per million tokens: [input, output]. Unknown models use the Opus 5 price. */
    public const PRICES = [
        'claude-fable-5-2' => [8.0, 40.0],
        'claude-fable-5-1' => [10.0, 50.0],
        'claude-fable-5' => [10.0, 50.0],
        'claude-opus-5-5' => [4.0, 20.0],
        'claude-opus-5' => [5.0, 25.0],
        'claude-opus-4-8' => [5.0, 25.0],
        'claude-opus-4-7' => [5.0, 25.0],
        'claude-opus-4-6' => [5.0, 25.0],
        'claude-sonnet-5-5' => [2.0, 10.0],
        'claude-sonnet-5' => [2.0, 10.0],
        'claude-sonnet-4-6' => [3.0, 15.0],
        'claude-haiku-5-5' => [0.10, 0.50],
        'claude-haiku-4-5' => [1.0, 5.0],
    ];

    /** Where the Anthropic account behind the key is topped up (Console → Billing). */
    public const BILLING_URL = 'https://console.anthropic.com/settings/billing';

    /** The Bahraini dinar is pegged at 0.376 to the dollar. */
    public const BHD_PER_USD = 0.376;

    /** What each feature is called on the settings page. */
    public const FEATURES = [
        'abdullah' => 'Abdullah messages',
        'whatsapp' => 'WhatsApp assistant',
        'bill_reader' => 'Reading bills',
        'dashboard_reader' => 'Reading dashboards (KM & fuel)',
    ];

    /**
     * Record one response. $message is the SDK's message object: its `model`
     * and `usage` (inputTokens, outputTokens, cache…InputTokens) are read.
     */
    public static function record(string $feature, object $message): void
    {
        try {
            if (! Schema::hasTable('ai_usage')) {
                return;
            }

            $usage = $message->usage ?? null;
            if (! is_object($usage)) {
                return;
            }

            $model = (string) ($message->model ?? '');
            $in = (int) ($usage->inputTokens ?? 0);
            $out = (int) ($usage->outputTokens ?? 0);
            $write = (int) ($usage->cacheCreationInputTokens ?? 0);
            $read = (int) ($usage->cacheReadInputTokens ?? 0);

            DB::table('ai_usage')->insert([
                'feature' => $feature,
                'model' => mb_substr($model, 0, 64),
                'input_tokens' => $in,
                'output_tokens' => $out,
                'cache_write_tokens' => $write,
                'cache_read_tokens' => $read,
                'cost_usd' => self::cost($model, $in, $out, $write, $read),
                'created_at' => Carbon::now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('AI usage not recorded: ' . $e->getMessage());
        }
    }

    public static function cost(string $model, int $in, int $out, int $cacheWrite = 0, int $cacheRead = 0): float
    {
        [$pin, $pout] = self::PRICES[$model] ?? self::PRICES[self::baseModel($model)] ?? self::PRICES['claude-opus-5'];

        return round(($in * $pin + $cacheWrite * $pin * 1.25 + $cacheRead * $pin * 0.1 + $out * $pout) / 1_000_000, 6);
    }

    /** "claude-opus-5-20260101" → "claude-opus-5", for a dated id. */
    private static function baseModel(string $model): string
    {
        return (string) preg_replace('/-\d{8}$/', '', $model);
    }

    /**
     * Spending between two moments, per feature.
     *
     * @return array{total: float, calls: int, features: array<string, array{cost: float, calls: int}>}
     */
    public static function between(Carbon $from, Carbon $to): array
    {
        $out = ['total' => 0.0, 'calls' => 0, 'features' => []];
        if (! Schema::hasTable('ai_usage')) {
            return $out;
        }

        $rows = DB::table('ai_usage')->whereBetween('created_at', [$from, $to])
            ->groupBy('feature')
            ->selectRaw('feature, SUM(cost_usd) as cost, COUNT(*) as calls')
            ->get();
        foreach ($rows as $row) {
            $cost = round((float) $row->cost, 4);
            $out['features'][(string) $row->feature] = ['cost' => $cost, 'calls' => (int) $row->calls];
            $out['total'] += $cost;
            $out['calls'] += (int) $row->calls;
        }
        $out['total'] = round($out['total'], 4);

        return $out;
    }
}
