<?php

declare(strict_types=1);

namespace Modules\Rental\Services;

use App\Erp\Abdullah\Abdullah;
use App\Erp\Settings\CompanyTimezone;
use App\Erp\Views\ValueFormat;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Rental\Models\RentalOrder;
use Throwable;

/**
 * What Abdullah notices when someone saves a car-rental order: praise and a
 * push for the next one on a new order, and a question when the daily rate is
 * twice the car's own daily rate or more, or half of it or less.
 *
 * Nothing here can stop a save: any failure is logged and the order stands.
 */
final class AbdullahOrderWatch
{
    public const RATE_FACTOR = 2.0;

    public function __construct(private readonly Abdullah $abdullah) {}

    public function saved(RentalOrder $order, bool $wasNew, ?User $user): void
    {
        if (! $user instanceof User || ! $this->abdullah->isOnFor($user)) {
            return;
        }

        try {
            $finding = $this->rateFinding($order);
            $key = 'check:rental-rate:order:' . $order->id;

            if ($wasNew) {
                $facts = $this->orderFacts($order, $user);
                if ($finding !== null) {
                    $facts['price_check'] = $finding;
                    $alert = $this->abdullah->alert($key, 'check', (int) $user->id);
                    $alert->forceFill(['sent_count' => $alert->sent_count + 1, 'last_sent_at' => Carbon::now(), 'resolved_at' => null])->save();
                }
                $this->abdullah->tell($user, 'booking', $facts);

                return;
            }

            if ($finding === null) {
                $this->abdullah->resolve($key);
            } else {
                $this->abdullah->once($user, $key, 'check', $finding);
            }
        } catch (Throwable $e) {
            Log::warning('Abdullah order check failed', ['order' => $order->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rateFinding(RentalOrder $order): ?array
    {
        $usual = (float) ($order->vehicle->daily_rate ?? 0);
        $rate = (float) $order->rate;
        if ($order->rate_type !== 'daily' || $usual <= 0.0 || $rate <= 0.0) {
            return null;
        }
        if ($rate < $usual * self::RATE_FACTOR && $rate > $usual / self::RATE_FACTOR) {
            return null;
        }

        $params = [
            'reference' => (string) $order->reference,
            'rate' => ValueFormat::money($rate),
            'usual' => ValueFormat::money($usual),
        ];

        return [
            'problem' => 'rental_rate',
            'reference' => (string) $order->reference,
            'rate' => $params['rate'],
            'usual' => $params['usual'],
            'direction' => $rate >= $usual ? 'too high' : 'too low',
            'summary_key' => 'Order :reference is at :rate a day, but this car\'s daily rate is :usual. Check the price.',
            'summary_params' => $params,
            'fix' => 'Open order ' . $order->reference . ' and check the rate.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function orderFacts(RentalOrder $order, User $user): array
    {
        $now = Carbon::now(CompanyTimezone::current());
        $from = static fn (Carbon $c): Carbon => $c->copy()->setTimezone(config('app.timezone'));
        $mine = static fn () => RentalOrder::query()->where('created_by_user_id', $user->id)->where('state', '!=', RentalOrder::STATE_CANCELLED);

        $today = $mine()->where('created_at', '>=', $from($now->copy()->startOfDay()));

        return [
            'reference' => (string) $order->reference,
            'customer' => (string) ($order->customer->name ?? ''),
            'car' => (string) ($order->vehicle?->displayName() ?? ''),
            'days' => (int) $order->days,
            'total' => ValueFormat::money((float) $order->total),
            'today_count' => (clone $today)->count(),
            'today_total' => ValueFormat::money((float) (clone $today)->sum('total')),
            'month_count' => $mine()->where('created_at', '>=', $from($now->copy()->startOfMonth()))->count(),
            'last_month_same_days_count' => $mine()
                ->where('created_at', '>=', $from($now->copy()->subMonthNoOverflow()->startOfMonth()))
                ->where('created_at', '<=', $from($now->copy()->subMonthNoOverflow()))
                ->count(),
        ];
    }
}
