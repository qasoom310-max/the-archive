<?php

declare(strict_types=1);

namespace Modules\Rental\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Rental\Models\RentalCustomer;
use Modules\Rental\Models\RentalOrder;
use Modules\Rental\Models\Vehicle;
use Throwable;

/**
 * Imports rental orders from a CSV (an export from a previous system) into
 * the orders list. Two shapes are understood:
 *
 *  - this screen's OWN export (Reference, Customer, Car, Pick-up, Return,
 *    Total, Received, Status, Payment), so a sheet pulled off the download
 *    comes back in without re-typing;
 *  - the old system's "Active Orders" export (Sl, RA#, Customer, Vehicle,
 *    Hire Period, Amount, VAT, Total, Receipt, Balance, Extra, Deposit, …),
 *    where the customer cell is "Name , CPR , phone", the vehicle is
 *    "plate - model" and the hire period is "31-Aug-26 12:42 to 17-Sep-26".
 *
 * An order whose reference (RA#) is already on file is skipped, never
 * rewritten — money recorded here since must not be overwritten by an old
 * sheet (`rental:fix-order-figures` exists for a deliberate figure refresh).
 * A row that cannot be read is skipped and logged; it never fails the upload.
 *
 * The old system's export is its ACTIVE ORDERS list (an RA# and a Hire
 * Period, no Status column), so every row in it is a car still out — even a
 * paid one past its return date, which the figures alone would call closed.
 * Such a row lands active, and an order already on file that the historical
 * migration brought in as closed is REOPENED (state only, never its money).
 * An order closed here in the ERP (a return was recorded) is left closed.
 *
 * "Extra" is not written: in the old system it sits OUTSIDE the total
 * (Amount + VAT = Total), so folding it in would change the total.
 */
final class OrderImporter
{
    /** @var array<string, string> spreadsheet header (lower) → canonical key */
    private const HEADER_MAP = [
        'reference' => 'reference', 'ra#' => 'reference', 'ra' => 'reference', 'ra no' => 'reference', 'ra no.' => 'reference',
        'customer' => 'customer',
        'car' => 'car', 'vehicle' => 'car',
        'pick-up' => 'pickup', 'pickup' => 'pickup', 'start date' => 'pickup', 'from' => 'pickup',
        'return' => 'return', 'end date' => 'return', 'to' => 'return',
        'hire period' => 'period', 'period' => 'period',
        'amount' => 'amount',
        'vat' => 'vat',
        'total' => 'total',
        'received' => 'received', 'receipt' => 'received', 'advance' => 'received', 'paid' => 'received',
        'balance' => 'balance',
        'deposit' => 'deposit',
        'status' => 'status',
        'payment' => 'payment',
    ];

    /**
     * @return array{imported: int, reopened: int, skipped: int, failed: int}
     */
    public function import(string $path): array
    {
        $result = ['imported' => 0, 'reopened' => 0, 'skipped' => 0, 'failed' => 0];

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return $result;
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);

            return $result;
        }

        $header[0] = preg_replace('/^\x{FEFF}/u', '', (string) $header[0]);

        $cols = [];
        foreach ($header as $i => $name) {
            $role = self::HEADER_MAP[strtolower(trim((string) $name))] ?? null;
            // The first column with a given meaning wins ("Amount" before
            // "Total" must not make Amount the total).
            if ($role !== null && ! isset($cols[$role])) {
                $cols[$role] = $i;
            }
        }

        if (! isset($cols['customer']) || (! isset($cols['total']) && ! isset($cols['amount']))) {
            fclose($handle);

            return $result;
        }

        $activeList = isset($cols['reference'], $cols['period']) && ! isset($cols['status']);

        while (($row = fgetcsv($handle)) !== false) {
            try {
                $outcome = $this->importRow($row, $cols, $activeList);
            } catch (Throwable $e) {
                Log::warning('Rental order import: row skipped', ['row' => $row, 'error' => $e->getMessage()]);
                $outcome = 'failed';
            }

            if ($outcome !== null) {
                $result[$outcome]++;
            }
        }
        fclose($handle);

        return $result;
    }

    /**
     * @param  array<int, string|null>  $row
     * @param  array<string, int>  $cols
     * @param  bool  $activeList  the file is the old system's active-orders list
     * @return 'imported'|'reopened'|'skipped'|null  null = an empty line
     */
    private function importRow(array $row, array $cols, bool $activeList): ?string
    {
        $cell = static fn (string $key): string => isset($cols[$key]) ? trim((string) ($row[$cols[$key]] ?? '')) : '';

        [$customerName, $cpr, $phone] = $this->splitCustomer($cell('customer'));
        $totalRaw = $cell('total') !== '' ? $cell('total') : $cell('amount');
        if ($customerName === '' || $totalRaw === '') {
            return null;
        }

        $reference = strtoupper($cell('reference'));
        $total = $this->money($totalRaw);
        $amount = $cell('amount') !== '' ? $this->money($cell('amount')) : $total;
        $vat = $this->money($cell('vat'));

        [$pickup, $return, $hiredTime] = $this->period($cell('period'), $cell('pickup'), $cell('return'));

        // Dedup: the RA# when the file carries one; otherwise the same
        // customer, the same pick-up date and the same total is almost
        // certainly the same order re-appearing in a second export.
        if ($reference !== '') {
            $existing = RentalOrder::query()->where('reference', $reference)->first();
            if ($existing !== null) {
                return $activeList && $this->reopen($existing) ? 'reopened' : 'skipped';
            }
        }
        if ($reference === '' && $pickup !== null && $this->alreadyImported($customerName, $pickup, $total)) {
            return 'skipped';
        }

        $received = min($this->money($cell('received')), $total);
        $balance = $cell('balance') !== '' ? max(0.0, $this->money($cell('balance'))) : round(max(0.0, $total - $received), 3);

        $customer = $this->resolveCustomer($customerName, $cpr, $phone);
        $vehicle = $this->resolveVehicle($cell('car'));

        $order = new RentalOrder([
            'customer_id' => $customer->id,
            'phone' => $phone !== '' ? $phone : null,
            'vehicle_id' => $vehicle?->id,
            'order_date' => $pickup ?? Carbon::now(),
            'start_date' => $pickup,
            'end_date' => $return,
            'hired_time' => $hiredTime,
            'rate_type' => 'daily',
            'subtotal' => $amount,
            'vat_amount' => $vat,
            'total' => $total,
            'advance_amount' => $received,
            'balance' => $balance,
            'state' => $activeList ? RentalOrder::STATE_ACTIVE : $this->orderState($cell('status'), $return, $balance),
            'payment_status' => $this->paymentStatus($received, $total, $balance),
            'notes' => __('Imported from previous system.'),
        ]);
        if ($reference !== '') {
            $order->reference = $reference;
        }
        if ($cell('deposit') !== '') {
            $order->deposit = $this->money($cell('deposit'));
        }
        if ($amount > 0 && $vat > 0) {
            $order->vat_rate = round($vat / $amount * 100, 2);
        }
        $order->save();

        if ($order->state === RentalOrder::STATE_ACTIVE) {
            $this->markCarOut($order);
        }

        return 'imported';
    }

    /**
     * The old system still lists this order as active, but it sits here as
     * closed — the historical migration judged it by its figures. Reopen it,
     * state only. Never an order closed in the ERP itself (a return was
     * recorded) and never a cancelled one: those are decisions made here.
     */
    private function reopen(RentalOrder $order): bool
    {
        if ($order->state !== RentalOrder::STATE_CLOSED || $order->returned_at !== null) {
            return false;
        }

        $order->state = RentalOrder::STATE_ACTIVE;
        $order->save();
        $this->markCarOut($order);

        return true;
    }

    /** An active order's car is out — but never overrule a car in maintenance. */
    private function markCarOut(RentalOrder $order): void
    {
        if ($order->vehicle_id === null) {
            return;
        }

        Vehicle::query()
            ->whereKey($order->vehicle_id)
            ->whereIn('status', [Vehicle::STATUS_AVAILABLE, Vehicle::STATUS_RESERVED])
            ->update(['status' => Vehicle::STATUS_RENTED]);
    }

    private function money(string $raw): float
    {
        return round((float) str_replace([',', ' '], '', $raw), 3);
    }

    /**
     * "Radu Mihai Carlig , 861174844 , +40732965400" → name, CPR, phone.
     * A plain name comes back as itself with no CPR or phone.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function splitCustomer(string $value): array
    {
        $parts = array_map('trim', explode(',', $value));
        if (count($parts) < 3) {
            return [trim($value), '', ''];
        }

        $phone = (string) array_pop($parts);
        $cpr = (string) array_pop($parts);
        // Only split when the tail really is an id and a number, so a company
        // name with a comma in it is never cut up.
        if (! preg_match('/^[0-9][0-9\-\/]*$/', $cpr) || ! preg_match('/^\+?[0-9 ]{6,}$/', $phone)) {
            return [trim($value), '', ''];
        }

        return [implode(', ', $parts), $cpr, $phone];
    }

    /**
     * @return array{0: ?Carbon, 1: ?Carbon, 2: ?string}  pick-up, return, pick-up time
     */
    private function period(string $period, string $pickup, string $return): array
    {
        if ($period !== '' && preg_match('/^(.+?)\s+to\s+(.+)$/i', $period, $m) === 1) {
            $pickup = $m[1];
            $return = $m[2];
        }

        $start = $this->parseDate($pickup);
        $end = $this->parseDate($return);
        $time = preg_match('/\b(\d{1,2}:\d{2})\b/', $pickup, $t) === 1 ? $t[1] : null;

        return [$start, $end, $time];
    }

    private function alreadyImported(string $customerName, Carbon $pickup, float $total): bool
    {
        return RentalOrder::query()
            ->whereHas('customer', fn ($c) => $c->where('name', $customerName))
            ->whereDate('start_date', $pickup->toDateString())
            ->whereBetween('total', [$total - 0.001, $total + 0.001])
            ->exists();
    }

    /** CPR first, then the phone's last 8 digits, then the exact name; else a new customer. */
    private function resolveCustomer(string $name, string $cpr, string $phone): RentalCustomer
    {
        if ($cpr !== '') {
            $byCpr = RentalCustomer::query()->where('cpr', $cpr)->orWhere('cr_number', $cpr)->first();
            if ($byCpr !== null) {
                return $byCpr;
            }
        }

        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($digits) >= 7) {
            $byPhone = RentalCustomer::query()->where('phone', 'like', '%' . substr($digits, -8))->first();
            if ($byPhone !== null) {
                return $byPhone;
            }
        }

        $byName = RentalCustomer::query()->where('name', $name)->first();
        if ($byName !== null) {
            return $byName;
        }

        return RentalCustomer::query()->create([
            'name' => $name,
            'cpr' => $cpr !== '' ? $cpr : null,
            'phone' => $phone !== '' ? $phone : null,
            'active' => true,
        ]);
    }

    /**
     * "239256 - FORD ECOSPORT" matches by plate, anything else by name. The
     * fleet should already be on file (Vehicle has its own import) — an
     * unmatched car is left null rather than inventing a fleet entry.
     */
    private function resolveVehicle(string $label): ?Vehicle
    {
        $label = trim($label);
        if ($label === '') {
            return null;
        }

        if (preg_match('/^([A-Za-z0-9]+)\s+-\s+(.+)$/', $label, $m) === 1) {
            $byPlate = Vehicle::query()->where('plate_no', $m[1])->first();
            if ($byPlate !== null) {
                return $byPlate;
            }
            $label = trim($m[2]);
        }

        return Vehicle::query()->where('name', 'like', "%{$label}%")->orWhere('plate_no', $label)->first();
    }

    /**
     * A Status column wins. Without one (the old system's active-orders list),
     * an order still owing money or not yet due back is active, else closed.
     */
    private function orderState(string $value, ?Carbon $return, float $balance): string
    {
        $state = match (strtolower(trim($value))) {
            'draft', 'reservation' => RentalOrder::STATE_DRAFT,
            'active', 'ongoing' => RentalOrder::STATE_ACTIVE,
            'cancelled', 'canceled' => RentalOrder::STATE_CANCELLED,
            'closed', 'completed', 'returned' => RentalOrder::STATE_CLOSED,
            default => null,
        };
        if ($state !== null) {
            return $state;
        }

        if (trim($value) === '' && ($balance > 0.0005 || ($return !== null && $return->endOfDay()->isFuture()))) {
            return RentalOrder::STATE_ACTIVE;
        }

        // Historical rows are, overwhelmingly, rentals that already closed.
        return RentalOrder::STATE_CLOSED;
    }

    private function paymentStatus(float $received, float $total, float $balance): string
    {
        return match (true) {
            $total <= 0.0 || $balance <= 0.0005 => RentalOrder::PAYMENT_PAID,
            $received > 0.0 => RentalOrder::PAYMENT_PARTIAL,
            default => RentalOrder::PAYMENT_UNPAID,
        };
    }

    private function parseDate(string $value): ?Carbon
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // The old system writes "31-Aug-26" / "31-Aug-26 12:42" — a two-digit
        // year that a free-form parse would misread.
        foreach (['d-M-y H:i', 'd-M-y', 'd-M-Y H:i', 'd-M-Y'] as $format) {
            try {
                $date = Carbon::createFromFormat('!' . $format, $value);
                if ($date instanceof Carbon && $date->format($format) === $value) {
                    return $date->startOfDay();
                }
            } catch (Throwable) {
                // try the next format
            }
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
