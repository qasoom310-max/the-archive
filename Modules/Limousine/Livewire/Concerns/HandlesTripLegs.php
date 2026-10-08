<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire\Concerns;

use App\Erp\Money\Currencies;
use App\Erp\Money\ExchangeRateService;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoLocation;
use Modules\Limousine\Models\LimoQuotation;
use Modules\Rental\Models\Vehicle;

/**
 * Shared trip-leg editing for the Limousine booking + quotation forms: an array
 * of legs (transfer or chauffeur), add/remove, validation, persistence and the
 * grand total. Both forms bind to `$legs` and render the `limousine::partials.legs`
 * editor.
 */
trait HandlesTripLegs
{
    /** @var list<array<string, string>> */
    public array $legs = [];

    /**
     * @return array<string, string>
     */
    protected function emptyLeg(): array
    {
        return [
            // Blank on a new leg; carries the DB row id once saved, so an edit
            // updates that row instead of replacing it and burning its reference.
            'id' => '',
            'service_type' => LimoLeg::TYPE_TRANSFER,
            'car_id' => '', 'from_location' => '', 'from_location_url' => '', 'to_location' => '', 'to_location_url' => '', 'start_at' => '',
            'hours' => '', 'days' => '1', 'car_details' => '',
            'rate' => '', 'currency' => LimoLeg::DEFAULT_CURRENCY, 'quote_rate' => '', 'exchange_rate' => '',
            'rate_basis' => LimoLeg::BASIS_TRIP, 'discount' => '', 'vat' => '',
        ];
    }

    public function addLeg(): void
    {
        $this->legs[] = $this->emptyLeg();
    }

    /**
     * Livewire lifecycle hook — fires after any public property is updated.
     * `rate` is BHD everywhere downstream ({@see LimoLeg::grossFor()},
     * `grandTotal()`, `persistLegs()`, the live total in the legs partial),
     * so a leg quoted in another currency keeps that contract by having its
     * BHD-typed `rate` DERIVED here rather than typed directly. The exchange
     * rate itself is never typed by the office — it's looked up live (see
     * {@see fetchExchangeRateFor()}) — so only `currency` and `quote_rate`
     * are bound to an input and can trigger this.
     */
    public function updated(string $name): void
    {
        // A chauffeur job is hours of a car, so switching a leg to chauffeur
        // prices it per hour: left on "per trip" the hours were ignored and an
        // 8-hour job at 20 BD an hour totalled 20. Back to a transfer, a
        // per-hour price goes back to per trip. A basis chosen by hand (per
        // day) is left alone.
        if (preg_match('/^legs\.(\d+)\.service_type$/', $name, $st) && isset($this->legs[(int) $st[1]])) {
            $leg = &$this->legs[(int) $st[1]];
            $basis = $leg['rate_basis'] ?? LimoLeg::BASIS_TRIP;
            if ($leg['service_type'] === LimoLeg::TYPE_CHAUFFEUR && $basis === LimoLeg::BASIS_TRIP) {
                $leg['rate_basis'] = LimoLeg::BASIS_HOUR;
            } elseif ($leg['service_type'] === LimoLeg::TYPE_TRANSFER && $basis === LimoLeg::BASIS_HOUR) {
                $leg['rate_basis'] = LimoLeg::BASIS_TRIP;
            }
            unset($leg);

            return;
        }

        if (! preg_match('/^legs\.(\d+)\.(currency|quote_rate)$/', $name, $m)) {
            return;
        }

        $i = (int) $m[1];
        if (! isset($this->legs[$i])) {
            return;
        }

        if ($m[2] === 'currency') {
            $this->switchLegCurrency($i);

            return;
        }

        $this->recomputeLegRate($i);
    }

    /**
     * Flip a leg between BHD (typed straight into `rate`) and a foreign
     * currency (typed as `quote_rate`, converted at a live-looked-up rate).
     *
     * Seeds the new currency's rate box with whatever BHD figure was already
     * there rather than dropping it — the office was quoted a number, and
     * switching currency mid-entry should not throw it away, only ask for
     * the rate to convert it with.
     */
    private function switchLegCurrency(int $i): void
    {
        $currency = $this->legs[$i]['currency'] ?? LimoLeg::DEFAULT_CURRENCY;

        if ($currency === LimoLeg::DEFAULT_CURRENCY) {
            $this->legs[$i]['quote_rate'] = '';
            $this->legs[$i]['exchange_rate'] = '';

            return;
        }

        $this->legs[$i]['quote_rate'] = $this->legs[$i]['rate'] ?? '';
        $this->legs[$i]['exchange_rate'] = '';
        $this->legs[$i]['rate'] = '';
        $this->fetchExchangeRateFor($i, $currency);
    }

    /**
     * Look up today's rate for one leg and re-derive its BHD `rate` from it.
     * Left blank (not thrown) on failure — {@see legRules()} blocks the save
     * until a rate is present, and the legs partial offers a Retry button.
     */
    private function fetchExchangeRateFor(int $i, string $currency): void
    {
        $rate = app(ExchangeRateService::class)->rate($currency, LimoLeg::DEFAULT_CURRENCY);
        $this->legs[$i]['exchange_rate'] = $rate !== null ? (string) $rate : '';
        $this->applyLegRate($i);
    }

    /** Called after typing the foreign amount: use the rate already on hand, or fetch one if we don't have it yet. */
    private function recomputeLegRate(int $i): void
    {
        $currency = $this->legs[$i]['currency'] ?? LimoLeg::DEFAULT_CURRENCY;

        if ($currency === LimoLeg::DEFAULT_CURRENCY) {
            return;
        }

        if (($this->legs[$i]['exchange_rate'] ?? '') === '' && ($this->legs[$i]['quote_rate'] ?? '') !== '') {
            $this->fetchExchangeRateFor($i, $currency);

            return;
        }

        $this->applyLegRate($i);
    }

    /** Pure computation from whatever currency/quote_rate/exchange_rate are already in state — never fetches. */
    private function applyLegRate(int $i): void
    {
        $leg = &$this->legs[$i];
        $currency = $leg['currency'] ?? LimoLeg::DEFAULT_CURRENCY;

        if ($currency === LimoLeg::DEFAULT_CURRENCY) {
            return;
        }

        $quoteRate = ($leg['quote_rate'] ?? '') !== '' ? (float) $leg['quote_rate'] : null;
        $exchangeRate = ($leg['exchange_rate'] ?? '') !== '' ? (float) $leg['exchange_rate'] : null;

        $leg['rate'] = $quoteRate !== null && $exchangeRate !== null
            ? (string) LimoLeg::bhdRate($currency, $quoteRate, $exchangeRate)
            : '';
    }

    /** Retry button in the legs partial, for when the live lookup failed. */
    public function retryLegExchangeRate(int $i): void
    {
        $currency = $this->legs[$i]['currency'] ?? null;

        if ($currency === null || $currency === LimoLeg::DEFAULT_CURRENCY) {
            return;
        }

        $this->fetchExchangeRateFor($i, $currency);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function currencyOptions(): array
    {
        return collect(Currencies::all())
            ->map(fn ($c): array => ['value' => $c->code, 'label' => $c->code . ' — ' . $c->name])
            ->values()
            ->all();
    }

    public function removeLeg(int $index): void
    {
        unset($this->legs[$index]);
        $this->legs = array_values($this->legs);
        if ($this->legs === []) {
            $this->legs = [$this->emptyLeg()];
        }
    }

    /** Seed one empty leg for a brand-new record. */
    protected function seedLegs(): void
    {
        if ($this->legs === []) {
            $this->legs = [$this->emptyLeg()];
        }
    }

    /** Load legs from a saved parent into the form array. */
    protected function loadLegs(LimoBooking|LimoQuotation $parent): void
    {
        $this->legs = $parent->legs->map(fn (LimoLeg $l): array => [
            'id' => (string) $l->id,
            'service_type' => $l->service_type,
            'car_id' => $l->car_id !== null ? (string) $l->car_id : '',
            'from_location' => $l->from_location ?? '',
            'from_location_url' => $l->from_location_url ?? '',
            'to_location' => $l->to_location ?? '',
            'to_location_url' => $l->to_location_url ?? '',
            'start_at' => $l->start_at?->format('Y-m-d\TH:i') ?? '',
            'hours' => $l->hours !== null ? (string) $l->hours : '',
            'days' => (string) $l->days,
            // A trip brought over from the old system recorded the car it was
            // booked for in `vehicle`, not `vehicle_details` — so fall back to
            // it, or editing an imported booking would ask for a car detail
            // the record has had all along.
            'car_details' => trim((string) ($l->vehicle_details ?? '')) !== '' ? (string) $l->vehicle_details : (string) ($l->vehicle ?? ''),
            'rate' => (string) $l->rate,
            'currency' => $l->currency ?? LimoLeg::DEFAULT_CURRENCY,
            'quote_rate' => $l->quote_rate !== null ? (string) $l->quote_rate : '',
            'exchange_rate' => $l->exchange_rate !== null ? (string) $l->exchange_rate : '',
            'rate_basis' => $l->rate_basis,
            'discount' => (string) $l->discount,
            'vat' => (string) $l->vat,
        ])->all();

        $this->seedLegs();
    }

    /**
     * Validation rules for the legs (per-index so transfer vs chauffeur differ).
     *
     * @return array<string, list<string>>
     */
    protected function legRules(): array
    {
        $rules = ['legs' => ['required', 'array', 'min:1']];

        foreach ($this->legs as $i => $leg) {
            $rules["legs.$i.service_type"] = ['required', 'in:transfer,chauffeur'];
            // The car is assigned later, when the trip is dispatched — not when
            // the booking is taken. A quotation never needs one at all. The
            // requirement is enforced at the point it actually matters, in
            // BookingForm::start().
            $rules["legs.$i.car_id"] = ['nullable', 'integer'];
            $rules["legs.$i.from_location"] = ['required', 'string', 'max:255'];
            // Map pins are optional, but must be a real URL if given — a
            // mistyped link is worse than none for a driver at speed.
            $rules["legs.$i.from_location_url"] = ['nullable', 'url', 'max:500'];
            $rules["legs.$i.to_location_url"] = ['nullable', 'url', 'max:500'];
            $rules["legs.$i.start_at"] = ['required', 'date'];
            // The car itself is picked at dispatch, so on a booking this line
            // is what says which car was asked for — the office needs it on
            // every trip. A quotation prices a service, not a named car, so
            // it stays optional there (see carDetailsRequired()).
            $rules["legs.$i.car_details"] = [$this->carDetailsRequired() ? 'required' : 'nullable', 'string', 'max:255'];
            $rules["legs.$i.rate"] = ['required', 'numeric', 'min:0'];
            $rules["legs.$i.currency"] = ['required', 'string', Rule::in(array_keys(Currencies::all()))];
            // Only asked for once a currency other than BHD is picked — BHD
            // needs no conversion, so a rate typed straight into `rate` is
            // already the figure everything downstream reads. `exchange_rate`
            // is never typed by the office (it's looked up live), so its
            // `required` here isn't "you forgot a field" — it's "the live
            // lookup hasn't produced a rate yet" (see messages() below).
            $foreign = ($leg['currency'] ?? LimoLeg::DEFAULT_CURRENCY) !== LimoLeg::DEFAULT_CURRENCY;
            $rules["legs.$i.quote_rate"] = [$foreign ? 'required' : 'nullable', 'numeric', 'min:0'];
            $rules["legs.$i.exchange_rate"] = [$foreign ? 'required' : 'nullable', 'numeric', 'min:0.000001'];
            $rules["legs.$i.rate_basis"] = ['required', 'in:trip,hour,day'];
            $rules["legs.$i.discount"] = ['nullable', 'numeric', 'min:0'];
            $rules["legs.$i.vat"] = ['nullable', 'numeric', 'min:0'];

            $chauffeur = ($leg['service_type'] ?? '') === LimoLeg::TYPE_CHAUFFEUR;
            $basis = $leg['rate_basis'] ?? LimoLeg::BASIS_TRIP;

            if ($chauffeur) {
                $rules["legs.$i.hours"] = ['required', 'numeric', 'min:0.5'];
                $rules["legs.$i.days"] = ['required', 'integer', 'min:1'];
            } else {
                $rules["legs.$i.to_location"] = ['required', 'string', 'max:255'];

                // Asked for only where the price actually depends on it, so a
                // per-hour trip cannot be saved with no hours and a total of
                // nothing — the failure it used to make silently.
                if ($basis === LimoLeg::BASIS_HOUR) {
                    $rules["legs.$i.hours"] = ['required', 'numeric', 'min:0.5'];
                } elseif ($basis === LimoLeg::BASIS_DAY) {
                    $rules["legs.$i.days"] = ['required', 'integer', 'min:1'];
                }
            }
        }

        return $rules;
    }

    /**
     * `legs.*.exchange_rate` is never a visible input (see {@see legRules()}),
     * so Laravel's default "The legs.0.exchange rate field is required."
     * would point at a field the office never sees. This is shown next to
     * the Retry button in the legs partial instead.
     *
     * @return array<string, string>
     */
    protected function messages(): array
    {
        $lookupFailed = __('Could not fetch today\'s exchange rate. Check your connection and try again.');

        return [
            'legs.*.car_details.required' => __('Say which car was asked for.'),
            'legs.*.exchange_rate.required' => $lookupFailed,
            'legs.*.exchange_rate.numeric' => $lookupFailed,
            'legs.*.exchange_rate.min' => $lookupFailed,
        ];
    }

    /** Grand total = sum of leg nets, recomputed from the live inputs. */
    public function grandTotal(): float
    {
        $sum = 0.0;
        foreach ($this->legs as $leg) {
            $sum += LimoLeg::netFor(
                $leg['rate_basis'] ?? LimoLeg::BASIS_TRIP,
                (float) ($leg['rate'] ?? 0),
                ($leg['hours'] ?? '') !== '' ? (float) $leg['hours'] : null,
                max(1, (int) ($leg['days'] ?? 1)),
                (float) ($leg['discount'] ?? 0),
                (float) ($leg['vat'] ?? 0),
            );
        }

        return round($sum, 3);
    }

    /**
     * A leg's start date, or null when it is not a date yet.
     *
     * The date box updates live, so the server re-renders on every keystroke
     * holding whatever the browser has at that instant — a year still standing
     * at `0000`, a pasted string, digits in another numeral set. `Carbon::parse`
     * throws on those, and a throw during render is a 500 on a form somebody is
     * halfway through filling. A half-typed date is an ordinary state of a form,
     * not an error: it reads as "no date yet" and the schedule waits for it.
     *
     * @param  array<string, string>  $leg
     */
    public function legStart(array $leg): ?Carbon
    {
        $value = trim($leg['start_at'] ?? '');

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (InvalidFormatException) {
            return null;
        }
    }

    /** Replace the parent's legs from the form array and recalc its total. */
    /**
     * Write the on-screen legs back to the parent, UPDATING rows that already
     * exist rather than replacing them.
     *
     * This used to `delete()` every leg and recreate the lot. That was harmless
     * while a leg was just a line of pricing, but a leg now owns a reference the
     * office quotes over the phone and a status it is dispatched by — recreating
     * it would issue a new number and reset its progress on every save. So each
     * on-screen leg carries its row id, existing rows are updated in place, and
     * only legs actually removed from the form are deleted.
     *
     * The ids arrive from the browser, so they are checked against the parent's
     * own legs: an id belonging to somebody else's booking is treated as new
     * rather than hijacked.
     */
    protected function persistLegs(LimoBooking|LimoQuotation $parent): void
    {
        $existing = $parent->legs()->get()->keyBy('id');
        $keptIds = [];

        // Snapshot the car label so a leg still shows its car if the fleet changes.
        $carIds = collect($this->legs)->pluck('car_id')->filter()->map(fn ($x): int => (int) $x)->all();
        $carLabels = Vehicle::query()->whereIn('id', $carIds)->get()
            ->mapWithKeys(fn (Vehicle $v): array => [$v->id => $v->displayName()]);

        foreach ($this->legs as $i => $leg) {
            $chauffeur = ($leg['service_type'] ?? '') === LimoLeg::TYPE_CHAUFFEUR;
            $basis = $leg['rate_basis'] ?? LimoLeg::BASIS_TRIP;
            $currency = $leg['currency'] ?? LimoLeg::DEFAULT_CURRENCY;
            $quoteRate = ($leg['quote_rate'] ?? '') !== '' ? (float) $leg['quote_rate'] : null;
            $exchangeRate = ($leg['exchange_rate'] ?? '') !== '' ? (float) $leg['exchange_rate'] : null;
            // The saved BHD rate is always DERIVED here from currency + quote
            // rate + exchange rate, never trusted from whatever the browser
            // last computed client-side — the same reasoning every other
            // money figure in this method is recomputed server-side for.
            $rate = $currency === LimoLeg::DEFAULT_CURRENCY
                ? (float) ($leg['rate'] === '' ? '0' : $leg['rate'])
                : LimoLeg::bhdRate($currency, $quoteRate ?? 0.0, $exchangeRate);
            // A quantity counts wherever the PRICE depends on it — a chauffeur
            // leg always, any other leg when its rate is per hour or per day.
            // Reading these off the service type alone left an hourly transfer
            // multiplying by nothing, so it saved at 0.00 however big the rate.
            $wantsHours = $chauffeur || $basis === LimoLeg::BASIS_HOUR;
            $wantsDays = $chauffeur || $basis === LimoLeg::BASIS_DAY;

            $hours = $wantsHours && ($leg['hours'] ?? '') !== '' ? (float) $leg['hours'] : null;
            $days = $wantsDays ? max(1, (int) (($leg['days'] ?? '') === '' ? '1' : $leg['days'])) : 1;
            $discount = (float) ($leg['discount'] === '' ? '0' : $leg['discount']);
            $vat = (float) ($leg['vat'] === '' ? '0' : $leg['vat']);
            $carId = ($leg['car_id'] ?? '') !== '' ? (int) $leg['car_id'] : null;

            $attributes = [
                'sequence' => $i,
                'service_type' => $leg['service_type'] ?? LimoLeg::TYPE_TRANSFER,
                'from_location' => $this->blankToNull($leg['from_location'] ?? ''),
                'from_location_url' => $this->blankToNull($leg['from_location_url'] ?? ''),
                'to_location' => $chauffeur ? null : $this->blankToNull($leg['to_location'] ?? ''),
                'to_location_url' => $chauffeur ? null : $this->blankToNull($leg['to_location_url'] ?? ''),
                'start_at' => ($leg['start_at'] ?? '') !== '' ? Carbon::parse($leg['start_at']) : null,
                'hours' => $hours,
                'days' => $days,
                'vehicle_details' => $this->blankToNull($leg['car_details'] ?? ''),
                'rate' => $rate,
                'currency' => $currency,
                'quote_rate' => $currency === LimoLeg::DEFAULT_CURRENCY ? null : $quoteRate,
                'exchange_rate' => $currency === LimoLeg::DEFAULT_CURRENCY ? null : $exchangeRate,
                'rate_basis' => $basis,
                'discount' => $discount,
                'vat' => $vat,
                'line_total' => LimoLeg::grossFor($basis, $rate, $hours, $days),
                'net_amount' => LimoLeg::netFor($basis, $rate, $hours, $days, $discount, $vat),
            ];

            // The car is only carried through the form where the form actually
            // offers one (quotations). On a booking it is assigned from the
            // queue, so leaving it out here keeps this from wiping it.
            if ($carId !== null) {
                $attributes['car_id'] = $carId;
                $attributes['vehicle'] = $carLabels[$carId] ?? null;
            }

            $id = (int) ($leg['id'] ?? 0);
            $row = $id > 0 ? $existing->get($id) : null;

            if ($row instanceof LimoLeg) {
                $row->fill($attributes)->save();
                $keptIds[] = $row->id;

                continue;
            }

            $created = $parent->legs()->create($attributes + [
                // A booking's legs are dispatched, so they start in the queue.
                // Quotation legs are not, and stay status-less.
                'status' => $parent instanceof LimoBooking ? LimoLeg::STATUS_QUEUE : null,
            ]);
            $keptIds[] = $created->id;
        }

        // Only legs genuinely taken off the form are removed.
        $parent->legs()->whereNotIn('id', $keptIds === [] ? [0] : $keptIds)->delete();

        $parent->recalcTotal();
        $parent->save();

        // A bulk delete fires no model events: the bill's service date has to
        // be re-read for the trips that are left.
        \Modules\Limousine\Support\InvoiceServiceDates::syncForParent($parent->getMorphClass(), (int) $parent->id);
    }

    private function blankToNull(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** Whether each leg must say which car was asked for. */
    protected function carDetailsRequired(): bool
    {
        return false;
    }

    /**
     * Shared view data for the legs editor.
     *
     * @return array<string, mixed>
     */
    protected function legViewData(): array
    {
        return [
            'carDetailsRequired' => $this->carDetailsRequired(),
            'serviceTypes' => LimoLeg::serviceTypeOptions(),
            'rateBasisOptions' => LimoLeg::rateBasisOptions(),
            'carOptions' => $this->carOptions(),
            'locationNames' => $this->locationSuggestions(),
            'grandTotal' => $this->grandTotal(),
            'currencyOptions' => $this->currencyOptions(),
        ];
    }

    /**
     * Every From/To field shares one datalist, listing the selected
     * customer's own repeat locations first — their trips are usually to
     * the same handful of places — then the company-wide saved list. Both
     * host forms declare `public ?int $customer_id`.
     *
     * @return list<string>
     */
    private function locationSuggestions(): array
    {
        /** @var int|null $customerId */
        $customerId = $this->customer_id ?? null;
        $customer = $customerId !== null ? LimoCustomer::query()->find($customerId) : null;

        $savedLocations = LimoLocation::query()->where('active', true)->orderBy('name')->pluck('name')->all();

        return collect($customer?->recentLocations() ?? [])
            ->merge($savedLocations)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Cars the limo desk can pick: cars that are available in Rent A Car (active
     * and not rented / reserved / in maintenance) — owned and outside alike —
     * plus any car already chosen on a leg (so editing never loses the selection).
     *
     * @return list<array{value: int, label: string}>
     */
    private function carOptions(): array
    {
        // "Available in Rent A Car" = active and not rented / reserved / in
        // maintenance. Papers aren't required here (many fleet cars have no
        // expiry dates entered), so the list isn't emptied by that.
        $availableIds = Vehicle::query()
            ->where('active', true)
            ->where('status', Vehicle::STATUS_AVAILABLE)
            ->pluck('id')
            ->all();

        $selectedIds = collect($this->legs)->pluck('car_id')->filter()->map(fn ($x): int => (int) $x)->all();
        $ids = array_values(array_unique([...$availableIds, ...$selectedIds]));

        if ($ids === []) {
            return [];
        }

        return Vehicle::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get(['id', 'name', 'plate_no', 'color', 'is_outside'])
            ->map(fn (Vehicle $v): array => [
                'value' => $v->id,
                'label' => $v->displayName() . ($v->is_outside ? ' · ' . __('Outside') : ''),
            ])
            ->all();
    }
}
