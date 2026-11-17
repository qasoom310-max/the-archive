<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use Illuminate\Support\Facades\DB;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoLocation;

/**
 * Where trips are picked up from most, read from the bookings.
 *
 * A trip's pick-up place is text on the trip, so the same place typed in a
 * different case or with a stray space is counted as one. Called-off trips are
 * left out: nobody was picked up. Each place says whether it is already on the
 * Locations list, so the ones the office keeps typing by hand can be added.
 */
final class PickupPlaces
{
    public function __construct(private readonly PlaceCountry $countries) {}

    /**
     * The busiest pick-up places, busiest first.
     *
     * @return list<array{key: string, name: string, country: string, trips: int, location_id: int|null}>
     */
    public function top(int $limit = 10): array
    {
        $rows = DB::table((new LimoLeg)->getTable())
            ->where('legable_type', (new LimoBooking)->getMorphClass())
            ->where(static fn ($q) => $q->where('status', '!=', LimoLeg::STATUS_CANCELLED)->orWhereNull('status'))
            ->whereNotNull('from_location')
            ->where('from_location', '!=', '')
            ->groupBy('from_location', 'from_country')
            ->selectRaw('from_location, from_country, count(*) as trips')
            ->get();

        $places = [];
        foreach ($rows as $row) {
            $name = trim((string) $row->from_location);
            if ($name === '') {
                continue;
            }
            $country = $this->countries->of($row->from_country, $name);
            $key = $country . '|' . mb_strtolower($name);

            // The spelling used most is the one shown.
            $places[$key] ??= ['key' => $key, 'name' => $name, 'country' => $country, 'trips' => 0, 'best' => 0];
            $places[$key]['trips'] += (int) $row->trips;
            if ((int) $row->trips > $places[$key]['best']) {
                $places[$key]['best'] = (int) $row->trips;
                $places[$key]['name'] = $name;
            }
        }

        usort($places, static fn (array $a, array $b): int => [-$a['trips'], $a['key']] <=> [-$b['trips'], $b['key']]);
        $places = array_slice($places, 0, max(1, $limit));

        $onFile = [];
        foreach (LimoLocation::query()->orderBy('id')->get(['id', 'name', 'country']) as $location) {
            $onFile[($location->country ?? LimoLocation::DEFAULT_COUNTRY) . '|' . mb_strtolower(trim($location->name))] ??= (int) $location->id;
        }

        return array_map(static fn (array $p): array => [
            'key' => $p['key'],
            'name' => $p['name'],
            'country' => $p['country'],
            'trips' => $p['trips'],
            'location_id' => $onFile[$p['key']] ?? null,
        ], $places);
    }
}
