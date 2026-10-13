<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer emails: each new quotation, invoice and receipt is emailed to the
 * customer, from an address of the business's choosing, with a terms PDF.
 *
 * Insert-only and per database (a core migration reaches every workspace).
 * Off everywhere except Wanaan, which asked for it (2026-10-13): there it is
 * switched on, sent from Erp@wanaan-bh.com, and the terms are the ones on
 * wanaan-bh.com (the terms page, the limousine pages' terms, the FAQ's
 * cross-border policy, and the services on the home page). A value somebody
 * already typed is never overwritten.
 */
return new class extends Migration
{
    /** @var list<array{key: string, type: string, label: string, sort: int, description: string}> */
    private array $params = [
        ['key' => 'mail.customer_documents', 'type' => 'bool', 'label' => 'Email documents to customers', 'sort' => 40, 'description' => 'Email every new quotation, invoice and receipt to the customer automatically.'],
        ['key' => 'mail.from_address', 'type' => 'string', 'label' => 'Send customer emails from', 'sort' => 41, 'description' => 'The address customers see the emails come from. Leave blank to use the server’s default.'],
        ['key' => 'company.customer_terms', 'type' => 'text', 'label' => 'Terms for customers', 'sort' => 42, 'description' => 'Sent as a PDF with every quotation, invoice and receipt emailed to a customer. Use # for headings and - for points. Leave blank to send none.'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('ir_config_parameter')) {
            return;
        }

        $isWanaan = $this->isWanaan();
        $wanaan = [
            'mail.customer_documents' => '1',
            'mail.from_address' => 'Erp@wanaan-bh.com',
            'company.customer_terms' => self::WANAAN_TERMS,
        ];

        foreach ($this->params as $p) {
            $value = $isWanaan ? $wanaan[$p['key']] : ($p['type'] === 'bool' ? '0' : '');
            $existing = DB::table('ir_config_parameter')->where('key', $p['key'])->first(['value']);

            if ($existing !== null) {
                if ($isWanaan && trim((string) $existing->value) === '') {
                    DB::table('ir_config_parameter')->where('key', $p['key'])->update(['value' => $value, 'updated_at' => now()]);
                }

                continue;
            }

            DB::table('ir_config_parameter')->insert([
                'key' => $p['key'],
                'value' => $value,
                'type' => $p['type'],
                'group' => 'General',
                'label' => $p['label'],
                'description' => $p['description'],
                'sort' => $p['sort'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        try {
            app(\App\Erp\Settings\SettingManager::class)->flush();
        } catch (Throwable) {
            // A stale cache only delays the change until the next save.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ir_config_parameter')) {
            return;
        }

        DB::table('ir_config_parameter')->whereIn('key', array_column($this->params, 'key'))->delete();
    }

    private function isWanaan(): bool
    {
        $name = strtolower((string) DB::table('ir_config_parameter')->where('key', 'company.name')->value('value'));
        $vat = preg_replace('/\D/', '', (string) DB::table('ir_config_parameter')->where('key', 'company.vat_number')->value('value'));

        return str_contains($name, 'wanaan') || $vat === '220015215500002';
    }

    private const WANAAN_TERMS = <<<'MD'
# Terms & Conditions

By confirming your booking you agree to the following policies.

## Limousine & chauffeur services

- **Cancellation:** if a reservation is cancelled more than 24 hours before pickup, a full refund is provided. If it is cancelled within 24 hours of pickup, the price is non-refundable.
- **Cross-border trips (Bahrain – Saudi Arabia):** at least 48 hours’ notice is required to cancel. Cancellations within 48 hours may incur a 50% cancellation fee, and no-shows are charged in full.
- **Airport pick-up waiting time:** one hour of waiting time is included at no extra cost. Waiting beyond the first hour is charged.
- **Changing a reservation:** changing the date or time of a limousine reservation carries a fee of 7.5 BD.
- All tolls and necessary fees are included in the price.
- Child car seats are available on request. Please give the child’s age and weight when booking.

## Car rental

**1. Driver & rental requirements**

- The minimum rental age is 25 years (some luxury vehicles may require a higher minimum age).
- A valid driving licence (Bahraini or international) and a passport or national ID are required.
- Additional drivers must be registered and approved by Wanaan Bahrain before use.

**2. Rental period & vehicle returns**

- All rentals are calculated on a 24-hour period.
- Late returns may incur additional daily charges.
- Extensions must be requested in advance and approved by our customer service team.

**3. Vehicle usage**

- Vehicles must be used responsibly and for legal purposes only.
- Off-road driving, racing, towing, or transporting illegal goods is strictly prohibited.
- Smoking inside the vehicle is not allowed (cleaning fees may apply).

**4. Fuel**

- Vehicles are provided with a full tank and must be returned full.
- Refuelling fees apply if the car is returned with less fuel.

**5. Insurance & liability**

- All rentals include basic insurance cover.
- The renter is responsible for any damage not covered by insurance, including negligence, reckless driving, or unregistered drivers.
- Additional insurance options are available on request.

**6. Accidents & breakdowns**

- In case of an accident, contact Wanaan Bahrain and the police immediately.
- Do not admit liability without the company’s authorisation.
- In the event of a mechanical breakdown, we provide roadside assistance or a replacement vehicle when available.

**7. Traffic fines & violations**

- The renter is fully responsible for traffic fines, toll charges and parking tickets during the rental period.

**8. Security deposit**

- A refundable security deposit is required before the vehicle is collected.
- Deposits are released within seven (7) working days after the vehicle’s return, provided there are no outstanding fines, damages or unpaid charges.

**9. Vehicle condition & cleanliness**

- Vehicles must be returned in the same condition as received.
- Extra charges may apply for damage, missing items or excessive dirt.

**10. Payment**

- Full payment must be completed before or at vehicle collection.
- We accept credit cards, bank transfers, Benefit Pay and cash (where available).

## Cancellation policy — car rental

- Cancelled 48 hours or more before pickup: no charge.
- Cancelled within 24 hours: 50% of the rental fee applies.
- Cancelled within 12 hours, or no-show: non-refundable.
- Refunds are issued as service vouchers, valid for future bookings with Wanaan Bahrain.

## Our other services

- **Car rental** — daily, weekly and monthly car hire across Bahrain.
- **Airport transfers** — seamless airport pick-up and drop-off with our limousine service.
- **VIP chauffeur service** — comfort, style and reliability with a professional chauffeur, by the hour or for the full day (8 to 12 hours).
- **Bahrain to Saudi Arabia (KSA)** — safe, comfortable and stress-free cross-border limousine and chauffeur travel.
- **Event transportation** — punctual, luxurious transfers for weddings, conferences and events of every kind.
- **Corporate transportation** — limousine and transport services for executives, companies and business travellers across the GCC, with monthly invoicing for corporate accounts.
- **City-to-city transfers** — travel within Bahrain and across the borders to Saudi Arabia and the GCC.
- **Bus rental** — modern buses with professional drivers for group travel, airport transfers, events and private tours.

Book at **www.wanaan-bh.com**, call or WhatsApp **+973 1747 4949**.
MD;
};
