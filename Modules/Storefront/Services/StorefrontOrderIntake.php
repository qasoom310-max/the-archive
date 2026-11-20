<?php

declare(strict_types=1);

namespace Modules\Storefront\Services;

use Illuminate\Support\Facades\DB;
use Modules\Pos\Models\PosProduct;
use Modules\Storefront\Models\StorefrontConfiguration;
use Modules\Storefront\Models\StorefrontOrder;

/**
 * Takes an order the website sends and files it in the inbox.
 *
 * The website says WHAT and HOW MANY; the ERP says how much. Every price is
 * read from the product here, and only products the catalog would show (and
 * that have the stock) are accepted — a basket built by hand cannot buy a
 * hidden product or name its own price.
 *
 * Idempotent on the website's own order number: a retried request returns the
 * order already filed instead of a second copy.
 */
final class StorefrontOrderIntake
{
    public const MAX_LINES = 30;

    public const MAX_QTY = 20;

    /**
     * @param  array<string, mixed>  $data
     * @return array{ok: true, order: StorefrontOrder, duplicate: bool}|array{ok: false, errors: array<string, mixed>}
     */
    public function receive(array $data): array
    {
        $reference = is_string($data['reference'] ?? null) ? trim($data['reference']) : '';
        if ($reference === '' || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $reference) !== 1) {
            return $this->fail(['reference' => 'reference is required: 1-64 letters, digits, dashes or underscores.']);
        }

        $existing = StorefrontOrder::query()->where('source_reference', $reference)->first();
        if ($existing !== null) {
            return ['ok' => true, 'order' => $existing, 'duplicate' => true];
        }

        $customer = is_array($data['customer'] ?? null) ? $data['customer'] : [];
        $name = $this->text($customer, 'name', 120);
        $phone = preg_replace('/\D+/', '', (string) ($customer['phone'] ?? '')) ?? '';
        // A local Bahrain number (8 digits) gets its country code, so the
        // register's WhatsApp receipt can reach it like any other order.
        if (strlen($phone) === 8) {
            $phone = '973' . $phone;
        }
        $address = $this->text($customer, 'address', 1000);
        $notes = $this->text($customer, 'notes', 1000);

        $errors = [];
        if ($name === '') {
            $errors['customer.name'] = 'The customer name is required.';
        }
        if (strlen($phone) < 8 || strlen($phone) > 15) {
            $errors['customer.phone'] = 'The phone number must have 8 to 15 digits.';
        }
        if ($address === '') {
            $errors['customer.address'] = 'The delivery address is required.';
        }

        $wanted = $this->wantedQuantities($data['lines'] ?? null);
        if ($wanted === null) {
            $errors['lines'] = 'lines must be a list of 1-' . self::MAX_LINES . ' items, each with a product_id and a qty of 1-' . self::MAX_QTY . '.';
        }

        if ($errors !== [] || $wanted === null) {
            return $this->fail($errors);
        }

        $products = PosProduct::query()->whereIn('id', array_keys($wanted))->get()->keyBy('id');

        $lines = [];
        $unavailable = [];
        foreach ($wanted as $productId => $qty) {
            $product = $products->get($productId);
            if (! $product instanceof PosProduct || ! $product->publishesOnline() || (float) $product->stock_on_hand < $qty) {
                $unavailable[] = $productId;

                continue;
            }

            $lines[] = self::line($product, $qty);
        }

        if ($unavailable !== []) {
            return $this->fail([
                'lines' => 'Some products are no longer available in that quantity.',
                'unavailable' => $unavailable,
            ]);
        }

        $subtotal = round(array_sum(array_column($lines, 'total')), 2);
        $delivery = StorefrontConfiguration::current()->deliveryChargeFor($subtotal);

        return DB::transaction(function () use ($reference, $name, $phone, $address, $notes, $lines, $subtotal, $delivery): array {
            // A second request with the same number racing this one.
            $raced = StorefrontOrder::query()->where('source_reference', $reference)->lockForUpdate()->first();
            if ($raced !== null) {
                return ['ok' => true, 'order' => $raced, 'duplicate' => true];
            }

            $order = StorefrontOrder::query()->create([
                'source_reference' => $reference,
                'status' => StorefrontOrder::STATUS_NEW,
                'customer_name' => $name,
                'customer_phone' => $phone,
                'delivery_address' => $address,
                'notes' => $notes !== '' ? $notes : null,
                'lines' => $lines,
                'subtotal' => $subtotal,
                'delivery_charge' => $delivery,
                'total' => round($subtotal + $delivery, 2),
            ]);

            return ['ok' => true, 'order' => $order, 'duplicate' => false];
        });
    }

    /**
     * One basket line, priced exactly as the register prices it
     * ({@see \Modules\Pos\Models\PosOrderLine::recompute()}): net × qty,
     * then tax on top. `unit_price` is the net price the register stores;
     * `total` is what the customer pays for the line.
     *
     * @return array{product_id: int, name: string, qty: int, unit_price: float, tax_rate: float, total: float}
     */
    public static function line(PosProduct $product, int $qty): array
    {
        $net = (float) $product->price;
        $tax = (float) $product->tax_rate;
        $subtotal = round($qty * $net, 2);

        return [
            'product_id' => (int) $product->id,
            'name' => (string) $product->name,
            'qty' => $qty,
            'unit_price' => $net,
            'tax_rate' => $tax,
            'total' => round($subtotal + round($subtotal * $tax / 100, 2), 2),
        ];
    }

    /**
     * product_id => qty, with repeated products merged. Null when the shape is
     * wrong in any way — a malformed basket is refused whole, not half-filed.
     *
     * @return array<int, int>|null
     */
    private function wantedQuantities(mixed $raw): ?array
    {
        if (! is_array($raw) || $raw === [] || count($raw) > self::MAX_LINES || ! array_is_list($raw)) {
            return null;
        }

        $wanted = [];
        foreach ($raw as $line) {
            if (! is_array($line)) {
                return null;
            }
            $id = $line['product_id'] ?? null;
            $qty = $line['qty'] ?? null;
            if (! is_int($id) || $id <= 0 || ! is_int($qty) || $qty < 1 || $qty > self::MAX_QTY) {
                return null;
            }
            $wanted[$id] = ($wanted[$id] ?? 0) + $qty;
            if ($wanted[$id] > self::MAX_QTY) {
                return null;
            }
        }

        return $wanted;
    }

    /** @param array<mixed> $source */
    private function text(array $source, string $key, int $max): string
    {
        $value = $source[$key] ?? null;
        if (! is_string($value)) {
            return '';
        }

        // Strip control characters (keep new lines in an address), then cap.
        $clean = preg_replace('/[^\P{C}\n]+/u', '', $value) ?? '';

        return mb_substr(trim($clean), 0, $max);
    }

    /**
     * @param  array<string, mixed>  $errors
     * @return array{ok: false, errors: array<string, mixed>}
     */
    private function fail(array $errors): array
    {
        return ['ok' => false, 'errors' => $errors];
    }
}
