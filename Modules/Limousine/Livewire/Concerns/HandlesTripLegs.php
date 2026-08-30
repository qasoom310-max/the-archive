<?php

declare(strict_types=1);

namespace Modules\Limousine\Livewire\Concerns;

use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;
use Modules\Limousine\Models\LimoBooking;
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
            'rate' => '0', 'rate_basis' => LimoLeg::BASIS_TRIP, 'discount' => '0', 'vat' => '0',
        ];
    }

    public function addLeg(): void
    {
        $this->legs[] = $this->emptyLeg();
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
            'car_details' => $l->vehicle_details ?? '',
            'rate' => (string) $l->rate,
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
            $rules["legs.$i.car_details"] = ['nullable', 'string', 'max:255'];
            $rules["legs.$i.rate"] = ['required', 'numeric', 'min:0'];
            $rules["legs.$i.rate_basis"] = ['required', 'in:trip,hour,day'];
            $rules["legs.$i.discount"] = ['nullable', 'numeric', 'min:0'];
            $rules["legs.$i.vat"] = ['nullable', 'numeric', 'min:0'];

            if (($leg['service_type'] ?? '') === LimoLeg::TYPE_CHAUFFEUR) {
                $rules["legs.$i.hours"] = ['required', 'numeric', 'min:0.5'];
                $rules["legs.$i.days"] = ['required', 'integer', 'min:1'];
            } else {
                $rules["legs.$i.to_location"] = ['required', 'string', 'max:255'];
            }
        }

        return $rules;
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
            $rate = (float) ($leg['rate'] === '' ? '0' : $leg['rate']);
            $hours = $chauffeur && $leg['hours'] !== '' ? (float) $leg['hours'] : null;
            $days = $chauffeur ? max(1, (int) ($leg['days'] === '' ? '1' : $leg['days'])) : 1;
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
    }

    private function blankToNull(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Shared view data for the legs editor.
     *
     * @return array<string, mixed>
     */
    protected function legViewData(): array
    {
        return [
            'serviceTypes' => LimoLeg::serviceTypeOptions(),
            'rateBasisOptions' => LimoLeg::rateBasisOptions(),
            'carOptions' => $this->carOptions(),
            'locationNames' => LimoLocation::query()->where('active', true)->orderBy('name')->pluck('name')->all(),
            'grandTotal' => $this->grandTotal(),
        ];
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
