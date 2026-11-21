<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use App\Erp\Abdullah\Abdullah;
use App\Erp\Settings\CompanyTimezone;
use App\Erp\Views\ValueFormat;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoLocation;
use Throwable;

/**
 * What Abdullah notices when someone saves a limousine booking.
 *
 * A new booking gets a word of praise and a push for the next one, with the
 * person's own numbers for today and this month. Then the ERP checks the
 * entry for slips it can prove, and Abdullah tells that person, once:
 *
 * - a trip priced at 3 times the usual for that kind of trip, or a third of
 *   it (the usual = the middle price of the last 120 days, from 8 trips or more);
 * - a place typed one or two letters off a saved place;
 * - more marked as paid than the booking costs;
 * - a new customer whose name or phone matches one already saved.
 *
 * Nothing here can stop a save: any failure is logged and the booking stands.
 */
final class AbdullahBookingWatch
{
    public const RATE_FACTOR = 3.0;

    public const RATE_SAMPLE = 8;

    public const RATE_DAYS = 120;

    public function __construct(private readonly Abdullah $abdullah) {}

    public function saved(LimoBooking $booking, bool $wasNew, ?User $user): void
    {
        if (! $user instanceof User || ! $this->abdullah->isOnFor($user)) {
            return;
        }

        try {
            $this->check($booking, $wasNew, $user);
        } catch (Throwable $e) {
            Log::warning('Abdullah booking check failed', ['booking' => $booking->id, 'error' => $e->getMessage()]);
        }
    }

    private function check(LimoBooking $booking, bool $wasNew, User $user): void
    {
        $legs = $booking->legs()->get()->filter(static fn (LimoLeg $leg): bool => $leg->status !== LimoLeg::STATUS_CANCELLED)->values();

        $rateFindings = [];
        foreach ($legs as $leg) {
            $key = 'check:rate:leg:' . $leg->id;
            $finding = $this->rateFinding($leg, $booking);
            if ($finding === null) {
                $this->abdullah->resolve($key);
            } else {
                $rateFindings[$key] = $finding;
            }
        }

        if ($wasNew) {
            $facts = $this->bookingFacts($booking, $legs->count(), $user);
            $first = array_key_first($rateFindings);
            if ($first !== null) {
                // Asked inside the praise, so it is not said twice.
                $facts['price_check'] = $rateFindings[$first];
                $alert = $this->abdullah->alert($first, 'check', (int) $user->id);
                $alert->forceFill(['sent_count' => $alert->sent_count + 1, 'last_sent_at' => Carbon::now(), 'resolved_at' => null])->save();
                unset($rateFindings[$first]);
            }
            $this->abdullah->tell($user, 'booking', $facts);
        }

        foreach ($rateFindings as $key => $finding) {
            $this->abdullah->once($user, $key, 'check', $finding);
        }

        $places = $this->savedPlaces();
        foreach ($legs as $leg) {
            foreach (['from' => (string) $leg->from_location, 'to' => (string) $leg->to_location] as $side => $typed) {
                $key = 'check:place:leg:' . $leg->id . ':' . $side;
                $saved = $this->nearPlace($typed, $places);
                if ($saved === null) {
                    $this->abdullah->resolve($key);

                    continue;
                }

                $this->abdullah->once($user, $key, 'check', [
                    'problem' => 'place',
                    'reference' => (string) $leg->reference,
                    'typed' => trim($typed),
                    'saved' => $saved,
                    'summary_key' => 'Trip :reference: ":typed" looks like the saved place ":saved". Pick the saved place so it is counted right.',
                    'summary_params' => ['reference' => (string) $leg->reference, 'typed' => trim($typed), 'saved' => $saved],
                    'fix' => 'Open booking ' . $booking->reference . ' and choose "' . $saved . '" from the list for trip ' . $leg->reference . '.',
                ]);
            }
        }

        $key = 'check:overpaid:booking:' . $booking->id;
        $total = $booking->netAmount();
        if ((float) $booking->advance > $total + 0.001) {
            $this->abdullah->once($user, $key, 'check', [
                'problem' => 'overpaid',
                'reference' => (string) $booking->reference,
                'paid' => ValueFormat::money((float) $booking->advance),
                'total' => ValueFormat::money($total),
                'summary_key' => 'Booking :reference shows :paid paid, but the total is only :total. Check the paid amount.',
                'summary_params' => ['reference' => (string) $booking->reference, 'paid' => ValueFormat::money((float) $booking->advance), 'total' => ValueFormat::money($total)],
                'fix' => 'Open booking ' . $booking->reference . ' and correct the paid amount.',
            ]);
        } else {
            $this->abdullah->resolve($key);
        }
    }

    /**
     * A customer was just added from the booking form.
     */
    public function customerAdded(LimoCustomer $customer, ?User $user): void
    {
        if (! $user instanceof User || ! $this->abdullah->isOnFor($user)) {
            return;
        }

        try {
            $match = $this->similarCustomer($customer);
            if ($match === null) {
                return;
            }

            $this->abdullah->once($user, 'check:customer:' . $customer->id, 'check', [
                'problem' => 'customer',
                'new' => (string) $customer->name,
                'existing' => $match,
                'summary_key' => 'The new customer ":new" looks like ":existing", who is already saved. Use the saved customer instead of making a second one.',
                'summary_params' => ['new' => (string) $customer->name, 'existing' => $match],
                'fix' => 'On the booking, pick "' . $match . '" from the customer list.',
            ]);
        } catch (Throwable $e) {
            Log::warning('Abdullah customer check failed', ['customer' => $customer->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rateFinding(LimoLeg $leg, LimoBooking $booking): ?array
    {
        $rate = (float) $leg->rate;
        if ($rate <= 0.0) {
            return null;
        }

        $rates = LimoLeg::query()
            ->whereMorphedTo('legable', LimoBooking::class)
            ->where('legable_id', '!=', $booking->id)
            ->where('service_type', $leg->service_type)
            ->where('rate_basis', $leg->rate_basis)
            ->where('rate', '>', 0)
            ->where('status', '!=', LimoLeg::STATUS_CANCELLED)
            ->where('created_at', '>=', Carbon::now()->subDays(self::RATE_DAYS))
            ->latest('id')
            ->limit(500)
            ->pluck('rate')
            ->map(static fn ($r): float => (float) $r)
            ->sort()
            ->values();

        if ($rates->count() < self::RATE_SAMPLE) {
            return null;
        }

        $mid = intdiv($rates->count(), 2);
        $usual = $rates->count() % 2 === 1 ? $rates[$mid] : ($rates[$mid - 1] + $rates[$mid]) / 2;
        if ($usual <= 0.0 || ($rate < $usual * self::RATE_FACTOR && $rate > $usual / self::RATE_FACTOR)) {
            return null;
        }

        $params = [
            'reference' => (string) $leg->reference,
            'rate' => ValueFormat::money($rate),
            'usual' => ValueFormat::money(round($usual, 3)),
        ];

        return [
            'problem' => 'rate',
            'reference' => (string) $leg->reference,
            'rate' => $params['rate'],
            'usual' => $params['usual'],
            'direction' => $rate >= $usual ? 'too high' : 'too low',
            'summary_key' => 'Trip :reference is priced at :rate, but this kind of trip is usually about :usual. Check the price.',
            'summary_params' => $params,
            'fix' => 'Open booking ' . $booking->reference . ', check the rate of trip ' . $leg->reference . ', and save.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bookingFacts(LimoBooking $booking, int $trips, User $user): array
    {
        $tz = CompanyTimezone::current();
        $now = Carbon::now($tz);
        $mine = static fn () => LimoBooking::query()->where('prepared_by', $user->name)->where('status', '!=', LimoBooking::STATUS_CANCELLED);
        $from = static fn (Carbon $c): Carbon => $c->copy()->setTimezone(config('app.timezone'));

        $today = $mine()->where('created_at', '>=', $from($now->copy()->startOfDay()));
        $month = $mine()->where('created_at', '>=', $from($now->copy()->startOfMonth()));
        $lastMonth = $mine()
            ->where('created_at', '>=', $from($now->copy()->subMonthNoOverflow()->startOfMonth()))
            ->where('created_at', '<=', $from($now->copy()->subMonthNoOverflow()));

        return [
            'reference' => (string) $booking->reference,
            'customer' => (string) ($booking->customer->name ?? ''),
            'trips' => $trips,
            'total' => ValueFormat::money($booking->netAmount()),
            'today_count' => (clone $today)->count(),
            'today_total' => ValueFormat::money((float) (clone $today)->sum('fare')),
            'month_count' => (clone $month)->count(),
            'last_month_same_days_count' => (clone $lastMonth)->count(),
        ];
    }

    /** @return array<string, string> lowercase name => name as saved */
    private function savedPlaces(): array
    {
        $out = [];
        foreach (LimoLocation::query()->pluck('name') as $name) {
            $clean = trim((string) $name);
            if ($clean !== '') {
                $out[mb_strtolower($clean)] = $clean;
            }
        }

        return $out;
    }

    /**
     * The saved place a typed one is one or two letters away from, or null
     * when it matches exactly, matches nothing close, or is too short to judge.
     *
     * @param  array<string, string>  $places
     */
    private function nearPlace(string $typed, array $places): ?string
    {
        $needle = mb_strtolower(trim($typed));
        if (mb_strlen($needle) < 5 || isset($places[$needle]) || strlen($needle) > 255) {
            return null;
        }

        $best = null;
        $bestDistance = 3;
        foreach ($places as $lower => $name) {
            if (mb_strlen($lower) < 5 || strlen($lower) > 255) {
                continue;
            }
            $distance = levenshtein($needle, $lower);
            if ($distance > 0 && $distance < $bestDistance) {
                $best = $name;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    private function similarCustomer(LimoCustomer $customer): ?string
    {
        $digits = substr((string) preg_replace('/\D+/', '', (string) $customer->phone), -8);
        $name = mb_strtolower(trim((string) $customer->name));

        foreach (LimoCustomer::query()->where('id', '!=', $customer->id)->latest('id')->limit(5000)->get(['id', 'name', 'phone']) as $other) {
            $otherDigits = substr((string) preg_replace('/\D+/', '', (string) $other->phone), -8);
            if (strlen($digits) === 8 && $digits === $otherDigits) {
                return (string) $other->name;
            }

            $otherName = mb_strtolower(trim((string) $other->name));
            if (mb_strlen($name) >= 5 && $otherName !== '' && strlen($otherName) <= 255 && levenshtein($name, $otherName) <= 2) {
                return (string) $other->name;
            }
        }

        return null;
    }
}
