<?php

declare(strict_types=1);

namespace Modules\Pos\Services;

use App\Erp\Settings\Setting;
use App\Models\ReportRecipient;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Modules\Pos\Enums\OrderState;
use Modules\Pos\Mail\DailyReportMail;
use Modules\Pos\Models\PosOrder;
use Modules\Pos\Models\PosOrderLine;
use Modules\Pos\Models\PosPayment;
use Modules\Pos\Models\PosProduct;

/**
 * Builds the daily sales + stock report for the cafe and emails it as a
 * professional PDF to the admin-managed recipient list.
 *
 * Business day: the venue opens at {@see OPEN_HOUR} (noon) and closes at
 * {@see CLOSE_HOUR} (6 AM next day). The scheduled job runs just after 6 AM
 * and reports on the night that just closed — i.e. yesterday 12:00 → today
 * 06:00 in the company timezone. All window bounds are computed in the
 * company timezone, then converted to the app/DB timezone for querying
 * (timestamps are stored in `config('app.timezone')`).
 */
final class DailyReport
{
    /** Cafe opening hour (local). */
    public const OPEN_HOUR = 12;

    /** Cafe closing hour (local, next morning). */
    public const CLOSE_HOUR = 6;

    /** Products at or below this on-hand qty are flagged low; ≤ 0 is "out". */
    public const LOW_STOCK_THRESHOLD = 10.0;

    /**
     * The timezone the cafe's clock runs in (for the open/close hours).
     */
    public function timezone(): string
    {
        $tz = Setting::get('company.timezone');

        if (is_string($tz) && $tz !== '') {
            return $tz;
        }

        $app = Config::get('app.timezone');

        return is_string($app) && $app !== '' ? $app : 'UTC';
    }

    /**
     * The business night that has just closed, relative to "now". Used by the
     * 6:10 AM scheduled send. Returns local-timezone bounds.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function lastClosedWindow(): array
    {
        $tz = $this->timezone();
        $end = Carbon::now($tz)->startOfDay()->addHours(self::CLOSE_HOUR); // today 06:00
        $start = $end->copy()->subDay()->setTime(self::OPEN_HOUR, 0);      // yesterday 12:00

        return [$start, $end];
    }

    /**
     * The current (or most-recently-closed) business night, for the live
     * dashboard card. Open in the evening / after midnight reports up to now;
     * while closed (06:00–12:00) it shows last night's closed totals.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function currentWindow(): array
    {
        $tz = $this->timezone();
        $now = Carbon::now($tz);
        $hour = (int) $now->format('G');

        if ($hour >= self::OPEN_HOUR) {
            // Evening: open now.
            return [$now->copy()->setTime(self::OPEN_HOUR, 0), $now];
        }

        if ($hour < self::CLOSE_HOUR) {
            // After midnight, still open.
            return [$now->copy()->subDay()->setTime(self::OPEN_HOUR, 0), $now];
        }

        // Closed (06:00–12:00): show last night.
        return $this->lastClosedWindow();
    }

    /**
     * Sales figures for a window. Window bounds are local; they're converted
     * to the DB timezone here. Only finalised (Done) orders count.
     *
     * @return array{
     *     revenue: float, orders: int, aov: float, discount_total: float,
     *     tax_total: float, payments: array<string, float>,
     *     top_products: list<array{name: string, qty: float, total: float}>
     * }
     */
    public function sales(Carbon $start, Carbon $end): array
    {
        $dbTz = $this->dbTimezone();
        $from = $start->copy()->setTimezone($dbTz);
        $to = $end->copy()->setTimezone($dbTz);

        $orders = PosOrder::query()
            ->where('state', OrderState::Done)
            ->where('ordered_at', '>=', $from)
            ->where('ordered_at', '<', $to)
            ->get();

        $orderIds = $orders->modelKeys();
        $count = $orders->count();
        $revenue = round((float) $orders->sum('total'), 2);

        $payments = [];
        if ($orderIds !== []) {
            $paymentRows = PosPayment::query()->with('method')
                ->whereIn('pos_order_id', $orderIds)->get();

            foreach ($paymentRows as $payment) {
                $method = $payment->method;
                $name = $method !== null ? $method->name : '—';
                $payments[$name] = round(($payments[$name] ?? 0.0) + (float) $payment->amount, 2);
            }
        }

        $topProducts = [];
        if ($orderIds !== []) {
            $lines = PosOrderLine::query()->whereIn('pos_order_id', $orderIds)->get();

            $grouped = [];
            foreach ($lines as $line) {
                $name = $line->name;
                $grouped[$name]['name'] = $name;
                $grouped[$name]['qty'] = ($grouped[$name]['qty'] ?? 0.0) + (float) $line->qty;
                $grouped[$name]['total'] = ($grouped[$name]['total'] ?? 0.0) + (float) $line->total;
            }

            usort($grouped, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);
            $topProducts = array_slice($grouped, 0, 5);
        }

        return [
            'revenue' => $revenue,
            'orders' => $count,
            'aov' => $count > 0 ? round($revenue / $count, 2) : 0.0,
            'discount_total' => round((float) $orders->sum('customer_discount_total'), 2),
            'tax_total' => round((float) $orders->sum('tax_total'), 2),
            'payments' => $payments,
            'top_products' => $topProducts,
        ];
    }

    /**
     * Current stock for every active product, out-of-stock then low-stock
     * first (the rest alphabetical), each flagged for the report highlight.
     *
     * @return array{
     *     rows: list<array{name: string, stock: float, low: bool, out: bool}>,
     *     low_count: int, out_count: int, total: int
     * }
     */
    public function stock(): array
    {
        $products = PosProduct::query()->where('active', true)->get();

        $rows = [];
        $lowCount = 0;
        $outCount = 0;

        foreach ($products as $product) {
            $stock = (float) $product->stock_on_hand;
            $out = $stock <= 0;
            $low = ! $out && $stock <= self::LOW_STOCK_THRESHOLD;

            if ($out) {
                $outCount++;
            } elseif ($low) {
                $lowCount++;
            }

            $rows[] = [
                'name' => (string) $product->name,
                'stock' => $stock,
                'low' => $low,
                'out' => $out,
            ];
        }

        // Out-of-stock first, then low, then the rest — each alphabetical.
        usort($rows, static function (array $a, array $b): int {
            $rank = static fn (array $r): int => $r['out'] ? 0 : ($r['low'] ? 1 : 2);

            return [$rank($a), $a['name']] <=> [$rank($b), $b['name']];
        });

        return [
            'rows' => $rows,
            'low_count' => $lowCount,
            'out_count' => $outCount,
            'total' => count($rows),
        ];
    }

    /**
     * Assemble the full report payload for a window (used by both the PDF
     * renderer and the email body).
     *
     * @return array<string, mixed>
     */
    public function build(Carbon $start, Carbon $end): array
    {
        $venue = Setting::get('company.name', 'OpenERP');

        return [
            'venue' => is_string($venue) && $venue !== '' ? $venue : 'OpenERP',
            'period_start' => $start,
            'period_end' => $end,
            'period_label' => $start->isoFormat('DD-MMM-YYYY h:mm A') . ' – ' . $end->isoFormat('DD-MMM-YYYY h:mm A'),
            'generated_at' => Carbon::now($this->timezone()),
            'sales' => $this->sales($start, $end),
            'stock' => $this->stock(),
            'low_threshold' => self::LOW_STOCK_THRESHOLD,
        ];
    }

    /**
     * Render the report payload to PDF bytes via DomPDF.
     *
     * @param  array<string, mixed>  $data
     */
    public function renderPdf(array $data): string
    {
        return Pdf::loadView('pos::daily-report-pdf', ['data' => $data])->setPaper('a4')->output();
    }

    /**
     * Build + email the report for the night that just closed. Returns the
     * number of recipients it was sent to (0 when none configured).
     */
    public function sendLastClosedReport(): int
    {
        [$start, $end] = $this->lastClosedWindow();

        return $this->sendForWindow($start, $end);
    }

    /**
     * Build + email the report for an explicit window. Shared by the
     * scheduled send and the dashboard "send now" action.
     */
    public function sendForWindow(Carbon $start, Carbon $end): int
    {
        $recipients = ReportRecipient::activeEmails();

        if ($recipients === []) {
            return 0;
        }

        $data = $this->build($start, $end);
        $pdf = $this->renderPdf($data);
        $venue = is_string($data['venue']) ? $data['venue'] : 'OpenERP';

        Mail::to($recipients)->send(new DailyReportMail($data, $pdf, $venue, $end));

        return count($recipients);
    }

    /**
     * The timezone Laravel stores timestamps in (so window bounds can be
     * converted before comparing to `ordered_at`).
     */
    private function dbTimezone(): string
    {
        $app = Config::get('app.timezone');

        return is_string($app) && $app !== '' ? $app : 'UTC';
    }
}
