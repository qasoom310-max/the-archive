<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use RuntimeException;
use Throwable;

/**
 * Brings bookings over from the previous limousine system's own list screens
 * (Active / Booking queue / Cancelled / Closed / Unpaid), whose CSV exports
 * each carry a slightly different set of columns.
 *
 * Unlike {@see BookingImporter} (the generic "Import" button), this keeps the
 * old system's booking number AS the booking's id — so "Booking #15452" is
 * BK/15452 here too, exactly as the 2026-09-05 Wanaan migration stored them —
 * and a number already on file is skipped, never overwritten. Only records the
 * ERP does not have yet are added.
 *
 * It deliberately issues no invoice and no receipt: those come over from their
 * own exports, and a booking brought across in this shape carries none (the
 * earlier migration's bookings don't either).
 */
final class LegacyBookingImporter
{
    /** Which list a file came from decides the status its trips land in. */
    public const KIND_STATUS = [
        'queue' => LimoBooking::STATUS_QUEUE,
        'confirmed' => LimoBooking::STATUS_CONFIRMED,
        'active' => LimoBooking::STATUS_ACTIVE,
        'closed' => LimoBooking::STATUS_COMPLETED,
        // The unpaid list is trips that already ran and still owe money: the
        // trip is completed, and the payment status follows from Received.
        'unpaid' => LimoBooking::STATUS_COMPLETED,
        'cancelled' => LimoBooking::STATUS_CANCELLED,
    ];

    /** Two phone numbers are one when they share an ending at least this long. */
    private const PHONE_SUFFIX_MIN = 7;

    /** @var array<string, string> header (lower-case) → canonical key */
    private const HEADER_MAP = [
        '#' => 'number',
        'from' => 'from', 'from date' => 'from',
        'to' => 'to', 'to date' => 'to',
        'type' => 'type',
        'customer' => 'customer',
        'pax' => 'pax', 'tel' => 'tel',
        'amount' => 'amount',
        'received' => 'received',
        'commission' => 'commission',
        'pickup' => 'pickup',
        'drop off' => 'dropoff',
        'vehicle' => 'vehicle',
        'details' => 'details',
        'driver' => 'driver',
        'added by' => 'added_by',
        'requested by' => 'requested_by',
        'comments' => 'comments',
        'status' => 'old_status',
        'booked' => 'booked', 'booked time' => 'booked',
    ];

    /** @var array<string, LimoCustomer> lower-case name → customer */
    private array $byName = [];

    /** @var array<string, list<LimoCustomer>> last N phone digits → customers */
    private array $byPhoneEnding = [];

    /**
     * @return array{
     *     imported: int,
     *     skipped: int,
     *     lines: list<string>,
     * }
     */
    public function import(string $path, string $status, bool $pretend = false): array
    {
        if (! in_array($status, self::KIND_STATUS, true)) {
            throw new InvalidArgumentException("Unknown booking status [{$status}].");
        }

        $rows = $this->readRows($path);
        $this->indexCustomers();

        $result = ['imported' => 0, 'skipped' => 0, 'lines' => []];

        DB::beginTransaction();

        try {
            foreach ($rows as $row) {
                $result['lines'][] = $this->importRow($row, $status, $result);
            }
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        // A dry run takes the exact same path, then throws every write away.
        $pretend ? DB::rollBack() : DB::commit();

        // Invoices imported before their booking was on file can link now.
        if (! $pretend) {
            LegacyInvoiceBookings::linkWaiting();
        }

        return $result;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array{imported: int, skipped: int, lines: list<string>}  $result
     */
    private function importRow(array $row, string $status, array &$result): string
    {
        $number = (int) preg_replace('/\D/', '', $row['number'] ?? '');
        $amount = $this->money($row['amount'] ?? '');
        $pickupAt = $this->parseDate($row['from'] ?? '');
        $customerText = $this->parseCustomer($row['customer'] ?? '');

        // The unpaid list prints the passenger in columns of its own.
        if (($row['pax'] ?? '') !== '' || ($row['tel'] ?? '') !== '') {
            $customerText['pax_name'] = $this->clean($row['pax'] ?? '');
            $customerText['pax_contact'] = $this->phone($row['tel'] ?? '');
        }

        $label = sprintf('#%d %s %s %s', $number, $customerText['name'], $pickupAt?->format('d-M-y H:i') ?? '?', number_format($amount, 3));

        if ($number <= 0 || $customerText['name'] === '' || $pickupAt === null) {
            $result['skipped']++;

            return "SKIP    {$label} — missing booking number, customer or date";
        }

        $existing = LimoBooking::query()->with('customer:id,name')->find($number);
        if ($existing !== null) {
            $result['skipped']++;

            // The earlier migration stored some times a minute early (20:29:59
            // for 20:30), so "the same booking" allows a couple of minutes.
            $same = strcasecmp(trim((string) $existing->customer?->name), $customerText['name']) === 0
                && $existing->pickup_at !== null
                && abs($existing->pickup_at->diffInMinutes($pickupAt)) <= 2;

            return $same
                ? "EXISTS  {$label}"
                : "CLASH   {$label} — BK/{$number} is already a different booking here ("
                    .($existing->customer->name ?? '?').' '.($existing->pickup_at?->format('d-M-y H:i') ?? '?').')';
        }

        [$customer, $how] = $this->resolveCustomer($customerText);

        // The same trip keyed in by hand in the ERP under another number. Only
        // bookings made here count: two old-system bookings carry two numbers
        // and are two trips (two cars at one time), so another booking brought
        // over from the old system ("Booking #…" notes) is never a twin. The
        // passenger counts too: two guests at one time and fare are two trips.
        $twin = LimoBooking::query()
            ->where('customer_id', $customer->id)
            ->where(fn ($q) => $q->whereNull('notes')->orWhere('notes', 'not like', 'Booking #%'))
            ->whereBetween('pickup_at', [$pickupAt->copy()->subMinute(), $pickupAt->copy()->addMinute()])
            ->whereBetween('fare', [$amount - 0.001, $amount + 0.001])
            ->when(
                $customerText['pax_name'] !== null,
                fn ($q) => $q->where(fn ($p) => $p->whereNull('pax_name')->orWhereRaw('lower(trim(pax_name)) = ?', [strtolower($customerText['pax_name'])])),
            )
            ->value('id');
        if ($twin !== null) {
            $result['skipped']++;

            return "TWIN    {$label} — same customer, time and fare as BK/{$twin}";
        }

        $received = min($this->money($row['received'] ?? ''), $amount);
        $chauffeur = str_contains(strtolower($row['type'] ?? ''), 'chauffeur');
        $toAt = $this->parseDate($row['to'] ?? '');
        [$pickup, $pickupUrl] = $this->splitLocation($row['pickup'] ?? '');
        [$dropoff, $dropoffUrl] = $this->splitLocation($row['dropoff'] ?? '');
        // The closed list splits the car into the plate that went (Vehicle)
        // and the type that was booked (Details); the others print the type.
        $carType = $this->clean($row['details'] ?? '') ?? $this->clean($row['vehicle'] ?? '');
        $driver = $this->clean($row['driver'] ?? '');
        $booked = $this->parseDate($row['booked'] ?? '') ?? $pickupAt;

        $booking = new LimoBooking;
        $booking->forceFill([
            'id' => $number,
            'reference' => 'BK/'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
            'booking_type' => $chauffeur ? 'hourly' : 'point_to_point',
            'customer_id' => $customer->id,
            'pax_name' => $customerText['pax_name'],
            'pax_contact' => $customerText['pax_contact'],
            'pickup_address' => $pickup,
            'dropoff_address' => $dropoff,
            'pickup_at' => $pickupAt,
            'booking_to' => $toAt !== null && ! $toAt->equalTo($pickupAt) ? $toAt : null,
            'num_cars' => 1,
            'car_details' => $carType,
            'fare' => $amount,
            'amount' => $amount,
            'discount' => 0,
            'advance' => $received,
            'requested_by' => $this->clean($row['requested_by'] ?? ''),
            'prepared_by' => $this->clean($row['added_by'] ?? ''),
            'status' => $status,
            'payment_status' => $amount > 0 && $received + 0.0005 >= $amount ? LimoBooking::PAYMENT_PAID : LimoBooking::PAYMENT_UNPAID,
            'notes' => $this->notes($number, $row, $driver),
        ]);
        $booking->created_at = $booked;
        // created_at is backdated to the historical booking date above, so
        // this is the only place that remembers the real moment the row
        // landed in this database — the signal that tells an old imported
        // trip apart from one entered live, regardless of reference number.
        $booking->imported_at = now();
        $booking->save();

        $hours = $chauffeur && $toAt !== null && $toAt->greaterThan($pickupAt)
            ? round($pickupAt->diffInMinutes($toAt) / 60, 2)
            : null;

        $booking->legs()->create([
            'sequence' => 1,
            'status' => $status,
            'service_type' => $chauffeur ? LimoLeg::TYPE_CHAUFFEUR : LimoLeg::TYPE_TRANSFER,
            'from_location' => $pickup,
            'from_location_url' => $pickupUrl,
            'to_location' => $dropoff,
            'to_location_url' => $dropoffUrl,
            'start_at' => $pickupAt,
            'hours' => $hours,
            'days' => 1,
            // The raw login the old system recorded; DriverAliases says who it
            // was at read time, so nothing here has to guess.
            'driver' => $driver,
            'vehicle' => $carType,
            'rate' => $amount,
            'rate_basis' => LimoLeg::BASIS_TRIP,
            'line_total' => $amount,
            'net_amount' => $amount,
        ]);

        $result['imported']++;

        return "NEW     {$label} — customer #{$customer->id} {$customer->name} ({$how})"
            .($customerText['pax_name'] !== null ? ", pax {$customerText['pax_name']}" : '');
    }

    /**
     * @return list<array<string, string>>
     */
    private function readRows(string $path): array
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path}.");
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);

            return [];
        }

        $cols = [];
        foreach ($header as $i => $name) {
            $key = self::HEADER_MAP[strtolower(trim((string) preg_replace('/^\x{FEFF}/u', '', (string) $name)))] ?? null;
            if ($key !== null && ! isset($cols[$key])) {
                $cols[$key] = $i;
            }
        }

        foreach (['number', 'from', 'customer', 'amount'] as $required) {
            if (! isset($cols[$required])) {
                fclose($handle);

                throw new RuntimeException("The file has no [{$required}] column.");
            }
        }

        $rows = [];
        while (($raw = fgetcsv($handle)) !== false) {
            if ($raw === [null]) {
                continue;
            }

            $row = [];
            foreach ($cols as $key => $i) {
                $row[$key] = trim((string) ($raw[$i] ?? ''));
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * The old system prints "Name,CPR/CR, phone" with the passenger welded
     * straight onto the phone: "Fursan Travel,0, +966 59 781 7502Adel - +973 3944 1093".
     *
     * @return array{name: string, phone: string|null, pax_name: string|null, pax_contact: string|null}
     */
    public function parseCustomer(string $raw): array
    {
        // Non-breaking spaces and the "?" a mangled one sometimes turns into.
        $raw = (string) preg_replace('/[\x{00A0}\x{200E}\x{200F}]/u', ' ', $raw);
        $parts = explode(',', $raw, 3);
        $name = trim((string) preg_replace('/\s+/', ' ', $parts[0]));
        $rest = trim($parts[2] ?? '', " ?\t");

        // The phone, brackets and dashes included: "+1 (719) 338-0669". A lone
        // "0" is the old system's placeholder for "no number".
        $phone = null;
        if (preg_match('/^([+(]?[0-9](?:[0-9()]|-(?=[0-9])| (?=[0-9(]))*)\??(.*)$/s', $rest, $m) === 1) {
            $digits = (string) preg_replace('/\D/', '', $m[1]);
            $phone = strlen($digits) >= 5 ? $this->phone((string) preg_replace('/[()\-]/', '', $m[1])) : null;
            $rest = $m[2];
        }

        // The passenger follows as "Name - phone"; a hyphen INSIDE a name
        // ("Al-Rawahi") has no space before it.
        $rest = trim($rest, " .?\t");
        $paxName = $rest;
        $paxContact = null;
        if (preg_match('/^(.*?)\s+-\s*(.*)$/s', ' '.$rest, $m) === 1) {
            $paxName = trim($m[1], " .\t");
            $paxContact = $this->phone($m[2]);
        }
        $paxName = trim((string) preg_replace('/\s+/', ' ', $paxName));

        return [
            'name' => $name,
            'phone' => $phone,
            'pax_name' => $paxName !== '' ? $paxName : null,
            'pax_contact' => $paxContact,
        ];
    }

    private function indexCustomers(): void
    {
        $this->byName = [];
        $this->byPhoneEnding = [];

        LimoCustomer::query()->orderBy('id')->each(function (LimoCustomer $customer): void {
            $this->remember($customer);
        });
    }

    private function remember(LimoCustomer $customer): void
    {
        $this->byName[strtolower(trim((string) $customer->name))] ??= $customer;

        $ending = $this->phoneEnding($customer->phone);
        if ($ending !== null) {
            $this->byPhoneEnding[$ending][] = $customer;
        }
    }

    /**
     * Name first (it is what the old system printed), then a shared phone
     * ending — the same rule the customer import uses — else a new customer.
     *
     * @param  array{name: string, phone: string|null, pax_name: string|null, pax_contact: string|null}  $text
     * @return array{0: LimoCustomer, 1: string}
     */
    private function resolveCustomer(array $text): array
    {
        $byName = $this->byName[strtolower($text['name'])] ?? null;
        if ($byName !== null) {
            return [$byName, 'matched by name'];
        }

        $ending = $this->phoneEnding($text['phone']);
        $byPhone = $ending !== null ? ($this->byPhoneEnding[$ending] ?? []) : [];
        if (count($byPhone) === 1) {
            return [$byPhone[0], 'matched by phone'];
        }

        $customer = LimoCustomer::query()->create([
            'name' => $text['name'],
            'type' => preg_match('/\b(w\.?l\.?l|company|co\.|travel|trad(e|ing)|group|hotel|llc|b\.?s\.?c|est)\b/i', $text['name']) === 1
                ? LimoCustomer::TYPE_COMPANY
                : 'individual',
            'phone' => $text['phone'],
            'active' => true,
        ]);
        $this->remember($customer);

        return [$customer, 'new customer'];
    }

    private function phoneEnding(?string $phone): ?string
    {
        $digits = ltrim((string) preg_replace('/\D/', '', (string) $phone), '0');

        return strlen($digits) >= self::PHONE_SUFFIX_MIN ? substr($digits, -self::PHONE_SUFFIX_MIN) : null;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function notes(int $number, array $row, ?string $driver): string
    {
        $parts = ["Booking #{$number}"];

        if (($row['old_status'] ?? '') !== '') {
            $parts[] = 'Status: '.$row['old_status'];
        }
        if ($driver !== null) {
            $parts[] = 'Driver: '.$driver;
        }
        if (($row['vehicle'] ?? '') !== '' && ($row['details'] ?? '') !== '') {
            $parts[] = 'Vehicle: '.$row['vehicle'];
        }
        if ($this->money($row['commission'] ?? '') > 0) {
            $parts[] = 'Commission: '.number_format($this->money($row['commission'] ?? ''), 3);
        }
        if (($row['comments'] ?? '') !== '') {
            $parts[] = $row['comments'];
        }

        return implode(' | ', $parts);
    }

    /**
     * "Sar Villa 1398, https://maps.google.com/?q=…" → place + map link.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function splitLocation(string $value): array
    {
        $url = null;
        if (preg_match('~^(.*?)(https?://\S+)\s*$~s', $value, $m) === 1) {
            [$value, $url] = [$m[1], $m[2]];
        }

        // "Riffa - Villa 879-https://…" and the "." the old system took for
        // "no address" both leave debris behind.
        return [$this->clean(trim($value, " \t,.-")), $url];
    }

    private function phone(string $value): ?string
    {
        $value = (string) preg_replace('/\s+/', '', $value);
        $value = trim($value, ".-");

        return $value !== '' ? $value : null;
    }

    private function money(string $value): float
    {
        return round((float) str_replace(',', '', $value), 3);
    }

    private function parseDate(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (['d-M-y H:i', 'd-M-y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);
                if ($date !== null) {
                    return $format === 'd-M-y' ? $date->startOfDay() : $date->second(0);
                }
            } catch (Throwable) {
                // try the next shape
            }
        }

        return null;
    }

    private function clean(string $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        return $value !== '' ? $value : null;
    }
}
