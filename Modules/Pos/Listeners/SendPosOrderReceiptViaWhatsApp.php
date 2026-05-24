<?php

declare(strict_types=1);

namespace Modules\Pos\Listeners;

use App\Erp\Money\Currencies;
use App\Erp\Settings\Setting;
use Illuminate\Support\Carbon;
use Modules\Contacts\Models\Partner;
use Modules\Pos\Events\PosOrderPaid;
use Modules\Pos\Models\PosOrder;
use Modules\WhatsApp\Exceptions\WhatsAppException;
use Modules\WhatsApp\Models\WhatsAppConfiguration;
use Modules\WhatsApp\Services\WhatsAppService;
use Throwable;

/**
 * Auto-receipt: when an order is finalised, queue a WhatsApp template
 * message to the customer phone captured at checkout.
 *
 * Wired by hand in {@see \Modules\Pos\Providers\PosServiceProvider::boot()};
 * not in `EventServiceProvider` because POS lives in `Modules/` and the
 * provider only boots while the module is installed.
 *
 * Synchronous on purpose — `WhatsAppService::sendTemplateMessage()` does
 * the heavy lifting on a queued job, so the listener's own work is just
 * "validate phone, build 4 strings, dispatch". Cashier UI never blocks.
 *
 * Errors from the WhatsApp side are caught and logged to the order's
 * Chatter (not thrown) — a misconfigured WhatsApp must NEVER break the
 * cashier's checkout flow. The DB sale is already committed when this
 * listener runs.
 */
final class SendPosOrderReceiptViaWhatsApp
{
    public function __construct(private readonly WhatsAppService $whatsapp)
    {
    }

    public function handle(PosOrderPaid $event): void
    {
        $order = $event->order;
        $phone = $order->customer_phone;

        // Walk-in customer with no phone — nothing to do. Not an error,
        // just the most common path; we don't even log to Chatter.
        if ($phone === null || trim($phone) === '') {
            return;
        }

        $variables = $this->buildTemplateVariables($order);

        // Meta locale code the template was approved under. Read from
        // config so admins can flip 'en' / 'en_US' / 'ar' / etc. in the
        // Settings UI without redeploying. Fallback to 'en' (the most
        // common default when a template is created under English in
        // WhatsApp Manager). Old hard-coded 'en_US' was wrong for the
        // typical account — they get #132001 "Template name does not
        // exist in the translation" because Meta has it under 'en'.
        $config = WhatsAppConfiguration::current();
        $language = (string) $config->template_language !== '' ? (string) $config->template_language : 'en';

        try {
            $this->whatsapp->sendTemplateMessage(
                to: $phone,
                template: 'pos_receipt',
                variables: $variables,
                languageCode: $language,
            );

            $order->logChange("WhatsApp receipt queued to +{$phone} (template: pos_receipt, lang: {$language}).");
        } catch (WhatsAppException $e) {
            // Expected operational failure — WhatsApp not configured /
            // disabled / missing template. Surface on Chatter so the
            // cashier sees something went wrong without breaking the sale.
            $order->logChange("WhatsApp receipt skipped: {$e->getMessage()}");
        } catch (Throwable $e) {
            // Unexpected — still don't let it escape and roll up into
            // the cashier's UI as a stack trace.
            $order->logChange("WhatsApp receipt failed unexpectedly: {$e->getMessage()}");
        }
    }

    /**
     * Build the 5 positional template variables expected by `pos_receipt`:
     *   {{1}} customer name (or "Walk-in"), {{2}} store name (from
     *   `company.name`), {{3}} order reference, {{4}} total with currency,
     *   {{5}} ordered datetime. Meta requires variables to appear in
     *   numerical order in the body, so the store goes in as {{2}} and
     *   the rest shift one position up from the original 4-variable layout.
     *
     * @return list<string>
     */
    private function buildTemplateVariables(PosOrder $order): array
    {
        // Resolve the customer name with two explicit null checks rather
        // than `?->` / `??` — Larastan infers `$order->partner` (relation
        // magic property) as non-null AND treats the ternary as collapsing
        // to non-null, so the chained nullsafe form trips `nullsafe.neverNull`.
        // Plain `if`s keep PHPStan calm while preserving runtime safety
        // (partner_id may be null; the looked-up row may have been deleted).
        $customerName = 'Walk-in';
        if ($order->partner_id !== null) {
            $partner = Partner::query()->find($order->partner_id);
            if ($partner !== null) {
                $customerName = $partner->name;
            }
        }

        // Store / brand name from the General settings tab. Same source
        // the on-screen receipt and login page use — keeps the WhatsApp
        // template in lockstep with the visible branding. Falls back to
        // "OpenERP" only if the setting was never written.
        $storeName = (string) Setting::get('company.name', 'OpenERP');

        // Active currency is baked into the formatted string (e.g. "10.00 BD"
        // for BHD, "$10.00" for USD) by the central registry — keeps the
        // template variable in lockstep with the in-app display.
        $totalFormatted = Currencies::format($order->total);

        // Ordered timestamp falls back to "now" if the order isn't yet
        // stamped (defensive — finalizeSale() always sets it via markPaid).
        // 12-hour clock (`g:i A`) so the WhatsApp template renders e.g.
        // "May 24, 2026 6:27 PM" instead of "May 24, 2026 18:27" — matches
        // the on-screen receipt and is what every retail POS uses here.
        $orderedAt = $order->ordered_at ?? Carbon::now();
        $orderedFormatted = $orderedAt->format('M j, Y g:i A');

        return [
            $customerName,
            $storeName,
            $order->reference,
            $totalFormatted,
            $orderedFormatted,
        ];
    }
}
