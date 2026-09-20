<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Assistant;

use App\Erp\Activity\ActivityLogger;
use App\Erp\Money\Currencies;
use App\Erp\Pricing\FareCalculator;
use App\Erp\Pricing\FareResult;
use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCustomer;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Models\LimoPaymentLink;
use Modules\Limousine\Models\LimoPortalConfiguration;
use Modules\Limousine\Models\LimoQuotation;
use Modules\Limousine\Services\LimoInvoicePdf;
use Modules\Limousine\Services\QuotationPdf;
use Modules\Limousine\Services\ServiceOrderPdf;
use Modules\Limousine\Services\ServiceOrderPortalClient;
use Modules\WhatsApp\Models\AssistantPaymentLink;
use Modules\WhatsApp\Models\Conversation;
use Throwable;

/**
 * Everything the assistant is allowed to DO, split in two:
 *
 *  - `propose*()` checks the request and returns the summary the staff member
 *    must answer YES to. It writes nothing.
 *  - `execute()` runs a proposal after that YES. It is the only path to a
 *    booking, a quotation, a PDF or a payment link.
 *
 * Both halves check the acting user's ERP permissions, and `execute()` re-reads
 * the fare from the tables rather than trusting the proposal it was handed.
 */
final class AssistantActions
{
    public const BOOKING = 'booking';

    public const QUOTATION = 'quotation';

    public const DOCUMENT = 'document';

    public const PAYMENT_LINK = 'payment_link';

    public function __construct(
        private readonly FareCalculator $fares,
        private readonly AccessControl $access,
        private readonly ActivityLogger $activity,
    ) {
    }

    /* ── Read-only tools ───────────────────────────────────────────────── */

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function fare(array $input): array
    {
        $travel = null;
        $raw = is_scalar($input['travel_date'] ?? null) ? trim((string) $input['travel_date']) : '';
        if ($raw !== '') {
            try {
                $travel = CarbonImmutable::parse($raw, 'Asia/Bahrain');
            } catch (Throwable) {
                $travel = null;
            }
        }

        $result = $this->fares->quote(
            serviceId: is_scalar($input['service'] ?? null) ? (string) $input['service'] : '',
            carId: is_scalar($input['car'] ?? null) ? (string) $input['car'] : '',
            optionCode: is_scalar($input['option'] ?? null) ? (string) $input['option'] : '',
            roundTrip: (bool) ($input['round_trip'] ?? false),
            extraHours: max(0.0, is_numeric($input['extra_hours'] ?? null) ? (float) $input['extra_hours'] : 0.0),
            travelDate: $travel,
        );

        return $result->found
            ? ['found' => true, 'amount_text' => $this->money($result->total)] + $result->toArray()
            : ['found' => false, 'reason' => $result->reason, 'instruction' => 'There is no set fare. Tell the staff member exactly that; never estimate a price.'];
    }

    /**
     * @return array<string, mixed>
     */
    public function findBooking(string $reference): array
    {
        $booking = $this->resolveBooking($reference);
        if ($booking === null) {
            return ['found' => false];
        }

        $leg = $booking->legs()->orderBy('sequence')->first();

        return [
            'found' => true,
            'booking' => (string) $booking->reference,
            'trip_reference' => $leg?->reference,
            'customer' => (string) ($booking->customer->name ?? ''),
            'pickup_at' => $booking->pickup_at?->format('Y-m-d H:i'),
            'from' => $leg?->from_location,
            'to' => $leg?->to_location,
            'amount' => $this->money($booking->netAmount()),
            'balance_due' => $this->money($booking->balanceDue()),
            'status' => $booking->status,
            'payment_status' => $booking->payment_status,
        ];
    }

    /* ── Proposals (write nothing) ────────────────────────────────────── */

    /**
     * @return array{ok: bool, action?: array<string, mixed>, error?: string, fare?: FareResult}
     */
    public function proposeTrip(string $type, TripRequest $trip, User $user): array
    {
        $modelKey = $type === self::QUOTATION ? 'limousine.quotation' : 'limousine.booking';
        if (! $this->access->allows($user, $modelKey, Permission::Create)) {
            return ['ok' => false, 'error' => 'forbidden'];
        }

        $missing = $trip->problems();
        if ($missing !== []) {
            return ['ok' => false, 'error' => 'missing:' . implode(',', $missing)];
        }

        $fare = $this->priceTrip($trip);
        if (! $fare->found) {
            return ['ok' => false, 'error' => 'no_fare'];
        }

        return [
            'ok' => true,
            'fare' => $fare,
            'action' => ['type' => $type, 'trip' => $trip->toArray(), 'amount' => $fare->total],
        ];
    }

    /**
     * @return array{ok: bool, action?: array<string, mixed>, error?: string, booking?: LimoBooking}
     */
    public function proposeDocument(string $reference, string $kind, User $user): array
    {
        if (! in_array($kind, ['invoice', 'service_order'], true)) {
            return ['ok' => false, 'error' => 'unknown_document'];
        }

        $modelKey = $kind === 'invoice' ? 'limousine.invoice' : 'limousine.booking';
        if (! $this->access->allows($user, $modelKey, Permission::Read)) {
            return ['ok' => false, 'error' => 'forbidden'];
        }

        $booking = $this->resolveBooking($reference);
        if ($booking === null) {
            return ['ok' => false, 'error' => 'booking_not_found'];
        }

        return ['ok' => true, 'booking' => $booking, 'action' => ['type' => self::DOCUMENT, 'kind' => $kind, 'booking_id' => $booking->id]];
    }

    /**
     * @return array{ok: bool, action?: array<string, mixed>, error?: string, booking?: LimoBooking}
     */
    public function proposePaymentLink(string $reference, ?float $amount, User $user): array
    {
        if (! $this->access->allows($user, 'limousine.booking', Permission::Write)) {
            return ['ok' => false, 'error' => 'forbidden'];
        }

        if (! LimoPortalConfiguration::current()->isConfigured()) {
            return ['ok' => false, 'error' => 'portal_off'];
        }

        $booking = $this->resolveBooking($reference);
        if ($booking === null) {
            return ['ok' => false, 'error' => 'booking_not_found'];
        }

        $balance = $booking->balanceDue();
        if ($balance <= 0) {
            return ['ok' => false, 'error' => 'nothing_owed'];
        }

        $amount = $amount === null || $amount <= 0 ? $balance : round(min($amount, $balance), 3);

        return ['ok' => true, 'booking' => $booking, 'action' => ['type' => self::PAYMENT_LINK, 'booking_id' => $booking->id, 'amount' => $amount]];
    }

    /* ── Execution (only after YES) ───────────────────────────────────── */

    /**
     * @param array<string, mixed> $action
     */
    public function execute(array $action, User $user, Conversation $conversation): ActionOutcome
    {
        $lang = $conversation->language;
        $type = (string) ($action['type'] ?? '');

        return match ($type) {
            self::BOOKING => $this->createBooking($action, $user, $lang),
            self::QUOTATION => $this->createQuotation($action, $user, $lang),
            self::DOCUMENT => $this->sendDocument($action, $user, $lang),
            self::PAYMENT_LINK => $this->createPaymentLink($action, $user, $conversation),
            default => new ActionOutcome(Replies::failed($lang)),
        };
    }

    /**
     * @param array<string, mixed> $action
     */
    private function createBooking(array $action, User $user, string $lang): ActionOutcome
    {
        if (! $this->access->allows($user, 'limousine.booking', Permission::Create)) {
            return new ActionOutcome(Replies::forbidden($lang));
        }

        $trip = TripRequest::fromArray(is_array($action['trip'] ?? null) ? $action['trip'] : []);
        $fare = $this->priceTrip($trip);
        if (! $fare->found || $trip->problems() !== []) {
            return new ActionOutcome(Replies::noFare($lang));
        }

        $booking = DB::transaction(function () use ($trip, $fare, $user): LimoBooking {
            $customer = $this->customerFor($trip);

            $booking = new LimoBooking();
            $booking->customer_id = $customer->id;
            $booking->pax_name = $trip->customerName;
            $booking->pax_contact = $trip->customerPhone;
            $booking->requested_by = $trip->customerName;
            $booking->prepared_by = (string) $user->name;
            $booking->payment_method = 'online';
            $booking->advance = 0;
            $booking->pickup_at = $trip->pickupAt !== null ? Carbon::instance($trip->pickupAt->toDateTime()) : Carbon::now();
            $booking->notes = $this->joinNotes('Booked via the WhatsApp assistant.', $trip->notes);
            $booking->save();

            $this->addLeg($booking, $trip, $fare, LimoLeg::STATUS_QUEUE);

            $booking->recalcTotal();
            $booking->save();
            $booking->syncPaymentFromAdvance();
            $booking->syncInvoice();

            return $booking->refresh();
        });

        $leg = $booking->legs()->orderBy('sequence')->first();
        $this->activity->logFor($booking, 'created', 'Booked via the WhatsApp assistant', $user);

        return new ActionOutcome(Replies::booked($lang, [
            'booking_no' => (string) $booking->reference . ($leg?->reference !== null ? ' · #' . $leg->reference : ''),
            'customer_name' => $trip->customerName,
            'customer_phone' => $trip->customerPhone,
            'car' => $lang === 'ar' ? $fare->carAr : $fare->carEn,
            'from' => $trip->from,
            'to' => $trip->to !== '' ? $trip->to : '—',
            'datetime' => $this->when($trip->pickupAt),
            'amount' => $this->money($fare->total),
        ]));
    }

    /**
     * @param array<string, mixed> $action
     */
    private function createQuotation(array $action, User $user, string $lang): ActionOutcome
    {
        if (! $this->access->allows($user, 'limousine.quotation', Permission::Create)) {
            return new ActionOutcome(Replies::forbidden($lang));
        }

        $trip = TripRequest::fromArray(is_array($action['trip'] ?? null) ? $action['trip'] : []);
        $fare = $this->priceTrip($trip);
        if (! $fare->found || $trip->problems() !== []) {
            return new ActionOutcome(Replies::noFare($lang));
        }

        $quote = DB::transaction(function () use ($trip, $fare, $user): LimoQuotation {
            $customer = $this->customerFor($trip);

            $quote = new LimoQuotation();
            $quote->quote_date = Carbon::now();
            $quote->customer_id = $customer->id;
            $quote->requested_by = $trip->customerName;
            $quote->prepared_by = (string) $user->name;
            $quote->contact_number = $trip->customerPhone;
            $quote->valid_until = Carbon::now()->addWeek();
            $quote->pickup_at = $trip->pickupAt !== null ? Carbon::instance($trip->pickupAt->toDateTime()) : Carbon::now();
            $quote->notes = $this->joinNotes('Prepared via the WhatsApp assistant.', $trip->notes);
            $quote->save();

            $this->addLeg($quote, $trip, $fare, null);

            $quote->recalcTotal();
            $quote->save();

            return $quote->refresh();
        });

        $this->activity->logFor($quote, 'created', 'Quotation prepared via the WhatsApp assistant', $user);

        $pdfs = app(QuotationPdf::class);

        return new ActionOutcome(
            Replies::documentReady($lang, 'quotation', (string) $quote->reference),
            $pdfs->render($quote),
            $pdfs->filename($quote),
        );
    }

    /**
     * @param array<string, mixed> $action
     */
    private function sendDocument(array $action, User $user, string $lang): ActionOutcome
    {
        $kind = (string) ($action['kind'] ?? '');
        $booking = LimoBooking::query()->find((int) ($action['booking_id'] ?? 0));
        if ($booking === null) {
            return new ActionOutcome(Replies::bookingNotFound($lang));
        }

        if ($kind === 'invoice') {
            if (! $this->access->allows($user, 'limousine.invoice', Permission::Read)) {
                return new ActionOutcome(Replies::forbidden($lang));
            }

            $invoice = $booking->createInvoice();
            $pdfs = app(LimoInvoicePdf::class);
            $this->activity->logFor($invoice, 'invoiced', 'Invoice PDF sent via the WhatsApp assistant', $user);

            return new ActionOutcome(Replies::documentReady($lang, 'invoice', (string) $invoice->reference), $pdfs->render($invoice), $pdfs->filename($invoice));
        }

        if (! $this->access->allows($user, 'limousine.booking', Permission::Read)) {
            return new ActionOutcome(Replies::forbidden($lang));
        }

        $leg = $booking->legs()->orderBy('sequence')->first();
        if (! $leg instanceof LimoLeg) {
            return new ActionOutcome(Replies::bookingNotFound($lang));
        }

        $pdfs = app(ServiceOrderPdf::class);
        $this->activity->logFor($booking, 'updated', 'Service order PDF sent via the WhatsApp assistant', $user);

        return new ActionOutcome(Replies::documentReady($lang, 'service_order', (string) $leg->reference), $pdfs->render($leg), $pdfs->filename($leg));
    }

    /**
     * @param array<string, mixed> $action
     */
    private function createPaymentLink(array $action, User $user, Conversation $conversation): ActionOutcome
    {
        $lang = $conversation->language;

        if (! $this->access->allows($user, 'limousine.booking', Permission::Write)) {
            return new ActionOutcome(Replies::forbidden($lang));
        }

        $booking = LimoBooking::query()->with('customer')->find((int) ($action['booking_id'] ?? 0));
        $leg = $booking?->legs()->orderBy('sequence')->first();
        if ($booking === null || ! $leg instanceof LimoLeg) {
            return new ActionOutcome(Replies::bookingNotFound($lang));
        }

        // Re-checked against the balance NOW, not when it was proposed.
        $amount = round(min((float) ($action['amount'] ?? 0), $booking->balanceDue()), 3);
        if ($amount <= 0) {
            return new ActionOutcome(Replies::nothingOwed($lang));
        }

        $link = LimoPaymentLink::query()->create([
            'leg_id' => $leg->id,
            'booking_id' => $booking->id,
            'amount' => $amount,
            'currency' => 'BHD',
            'created_by_user_id' => $user->id,
        ]);

        if (! app(ServiceOrderPortalClient::class)->push($link)) {
            // Same rule as the bookings screen: a half-made link is never handed out.
            $link->delete();

            return new ActionOutcome(Replies::paymentLinkFailed($lang));
        }

        AssistantPaymentLink::query()->create(['payment_link_id' => $link->id, 'conversation_id' => $conversation->id]);
        $this->activity->logFor($booking, 'updated', 'Payment link for ' . $this->money($amount) . ' created via the WhatsApp assistant', $user);

        return new ActionOutcome(Replies::paymentLink($lang, [
            'customer_name' => (string) ($booking->customer->name ?? $booking->pax_name ?? ''),
            'amount' => $this->money($amount),
            'link' => (string) $link->refresh()->url,
        ]));
    }

    /* ── Helpers ──────────────────────────────────────────────────────── */

    public function priceTrip(TripRequest $trip): FareResult
    {
        return $this->fares->quote(
            serviceId: $trip->service,
            carId: $trip->car,
            optionCode: $trip->option,
            roundTrip: $trip->roundTrip,
            extraHours: $trip->extraHours,
            travelDate: $trip->pickupAt,
        );
    }

    public function resolveBooking(string $reference): ?LimoBooking
    {
        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }

        $exact = LimoBooking::query()->where('reference', $reference)->first();
        if ($exact !== null) {
            return $exact;
        }

        $digits = preg_replace('/\D+/', '', $reference) ?? '';
        if ($digits === '') {
            return null;
        }

        // A trip (leg) number, the one the office quotes, e.g. "10043".
        $leg = LimoLeg::query()
            ->where('legable_type', (new LimoBooking())->getMorphClass())
            ->where('reference', $digits)
            ->first();
        if ($leg !== null) {
            return LimoBooking::query()->find($leg->legable_id);
        }

        return LimoBooking::query()->where('reference', 'BK/' . str_pad(ltrim($digits, '0'), 5, '0', STR_PAD_LEFT))->first();
    }

    public function money(float $amount): string
    {
        return Currencies::format($amount, 'BHD');
    }

    public function when(?CarbonImmutable $at): string
    {
        return $at?->format('D j M Y, g:i A') ?? '—';
    }

    private function addLeg(LimoBooking|LimoQuotation $parent, TripRequest $trip, FareResult $fare, ?string $status): void
    {
        // The fare before any offer goes on the rate, the offer on the
        // discount — so the documents show the saving rather than hiding it.
        $gross = round($fare->total + $fare->discount, 3);
        $chauffeur = $fare->hours !== null && $trip->to === '';

        $parent->legs()->create([
            'sequence' => 0,
            'status' => $status,
            'service_type' => $chauffeur ? LimoLeg::TYPE_CHAUFFEUR : LimoLeg::TYPE_TRANSFER,
            'from_location' => $trip->from,
            'to_location' => $chauffeur ? null : ($trip->to !== '' ? $trip->to : null),
            'start_at' => $trip->pickupAt !== null ? Carbon::instance($trip->pickupAt->toDateTime()) : null,
            'hours' => $fare->hours !== null ? (float) $fare->hours + $fare->extraHours : null,
            'days' => 1,
            // The car TYPE; the actual car and driver are assigned by dispatch.
            'vehicle_details' => $fare->carEn . ' — ' . $fare->serviceEn . ' (' . $fare->optionEn . ')' . ($fare->roundTrip ? ', return trip' : ''),
            'rate' => $gross,
            'currency' => LimoLeg::DEFAULT_CURRENCY,
            'rate_basis' => LimoLeg::BASIS_TRIP,
            'discount' => $fare->discount,
            'vat' => 0,
            'line_total' => LimoLeg::grossFor(LimoLeg::BASIS_TRIP, $gross, null, 1),
            'net_amount' => LimoLeg::netFor(LimoLeg::BASIS_TRIP, $gross, null, 1, $fare->discount, 0.0),
        ]);
    }

    /**
     * An existing customer with the same number, or a new one. A local number
     * and the same number with its country code are the same phone.
     */
    private function customerFor(TripRequest $trip): LimoCustomer
    {
        $digits = preg_replace('/\D+/', '', $trip->customerPhone) ?? '';
        $tail = strlen($digits) >= 7 ? substr($digits, -8) : $digits;

        if ($tail !== '') {
            $match = LimoCustomer::query()
                ->where('phone', 'like', '%' . substr($tail, -4) . '%')
                ->get()
                ->first(static function (LimoCustomer $c) use ($tail): bool {
                    $theirs = preg_replace('/\D+/', '', (string) $c->phone) ?? '';

                    return $theirs !== '' && str_ends_with($theirs, $tail);
                });

            if ($match instanceof LimoCustomer) {
                return $match;
            }
        }

        return LimoCustomer::query()->create([
            'name' => $trip->customerName,
            'type' => 'individual',
            'phone' => $trip->customerPhone,
            'active' => true,
        ]);
    }

    private function joinNotes(string $first, string $extra): string
    {
        return $extra !== '' ? $first . "\n" . $extra : $first;
    }
}
