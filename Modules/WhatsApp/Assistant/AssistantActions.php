<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Assistant;

use App\Erp\Activity\ActivityLogger;
use App\Erp\Money\Currencies;
use App\Erp\Pricing\FareCalculator;
use App\Erp\Pricing\FareResult;
use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use App\Erp\Targets\RevenueTargets;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
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

    public const EDIT_BOOKING = 'edit_booking';

    public function __construct(
        private readonly FareCalculator $fares,
        private readonly AccessControl $access,
        private readonly ActivityLogger $activity,
        private readonly RevenueTargets $targets,
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

    /**
     * Collected revenue for a period. OWNER-ONLY — revenue is locked to super
     * admins everywhere else in this ERP (the dashboard cards, the Rental/
     * Limousine money bands), and a chat channel is not an exception to that.
     * Checked against the REAL authenticated account, never a claim in the
     * message.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function salesSummary(array $input, User $user): array
    {
        if (! $user->isSuperAdmin()) {
            return [
                'found' => false,
                'error' => 'forbidden',
                'instruction' => 'Only the owner may see revenue figures. Say so plainly and do not share any number, even an estimate.',
            ];
        }

        $now = CarbonImmutable::now('Asia/Bahrain');
        $period = is_scalar($input['period'] ?? null) ? (string) $input['period'] : 'today';

        [$from, $to, $label] = match ($period) {
            'yesterday' => [$now->subDay()->startOfDay(), $now->subDay()->endOfDay(), 'Yesterday'],
            'this_week' => [$now->startOfWeek(), $now->endOfWeek(), 'This week'],
            'this_month' => [$now->startOfMonth(), $now->endOfMonth(), 'This month'],
            'custom' => [
                $this->parseDate($input['from'] ?? null) ?? $now->startOfDay(),
                $this->parseDate($input['to'] ?? null) ?? $now->endOfDay(),
                'Custom range',
            ],
            default => [$now->startOfDay(), $now->endOfDay(), 'Today'],
        };

        $paidBookings = LimoBooking::query()
            ->where('payment_status', 'paid')
            ->whereBetween('pickup_at', RevenueTargets::windowBounds($from, $to))
            ->count();

        return [
            'found' => true,
            'period' => $label,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'collected' => $this->money($this->targets->earned('limousine', $from, $to)),
            'still_owed_in_period' => $this->money($this->targets->outstanding('limousine', $from, $to)),
            'paid_bookings' => $paidBookings,
        ];
    }

    /**
     * A customer's profile, recent bookings and balance owed — by name or
     * phone. Read-only; the same Read permission `propose_document`'s
     * service-order path already requires.
     *
     * @return array<string, mixed>
     */
    public function findCustomer(string $query, User $user): array
    {
        if (! $this->access->allows($user, 'limousine.booking', Permission::Read)) {
            return ['found' => false, 'error' => 'forbidden', 'instruction' => "Your ERP permissions don't allow that. Tell them so; do not retry."];
        }

        $query = trim($query);
        if ($query === '') {
            return ['found' => false];
        }

        $digits = preg_replace('/\D+/', '', $query) ?? '';
        $customers = LimoCustomer::query()
            ->when(
                strlen($digits) >= 4,
                fn ($q) => $q->where('phone', 'like', '%' . $digits . '%'),
                fn ($q) => $q->where('name', 'like', '%' . $query . '%'),
            )
            ->limit(5)
            ->get();

        if ($customers->isEmpty()) {
            return ['found' => false];
        }

        return [
            'found' => true,
            'matches' => $customers->map(function (LimoCustomer $customer): array {
                $bookings = LimoBooking::query()->where('customer_id', $customer->id)->orderByDesc('pickup_at')->get();
                $balance = 0.0;
                foreach ($bookings as $booking) {
                    $balance += $booking->balanceDue();
                }

                return [
                    'name' => (string) $customer->name,
                    'phone' => (string) $customer->phone,
                    'type' => (string) $customer->type,
                    'total_bookings' => $bookings->count(),
                    'balance_due' => $this->money(round($balance, 3)),
                    'recent_bookings' => $bookings->take(5)->map(fn (LimoBooking $b): array => [
                        'reference' => (string) $b->reference,
                        'pickup_at' => $b->pickup_at?->format('Y-m-d H:i'),
                        'amount' => $this->money($b->netAmount()),
                        'status' => $b->status,
                    ])->values()->all(),
                ];
            })->values()->all(),
        ];
    }

    /* ── Proposals (write nothing) ────────────────────────────────────── */

    /**
     * @return array{ok: bool, action?: array<string, mixed>, error?: string, fare?: FareResult}
     */
    /**
     * $overrideAmount is a specific price INSTEAD of the table fare — never
     * trusted from the model's say-so alone. It only ever takes effect for a
     * genuine admin, checked here AND again at {@see execute()}; anyone else
     * asking for one is refused outright, whatever the message claims about
     * who they are.
     *
     * @return array{ok: bool, action?: array<string, mixed>, error?: string, fare?: FareResult, tableTotal?: float}
     */
    public function proposeTrip(string $type, TripRequest $trip, User $user, ?float $overrideAmount = null): array
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

        $tableTotal = $fare->total;
        $action = ['type' => $type, 'trip' => $trip->toArray()];

        if ($overrideAmount !== null) {
            if (! $user->isAdmin()) {
                return ['ok' => false, 'error' => 'override_forbidden'];
            }

            $fare = $this->withOverride($fare, $overrideAmount);
            $action['override_amount'] = $fare->total;
        }

        $action['amount'] = $fare->total;

        return ['ok' => true, 'fare' => $fare, 'tableTotal' => $tableTotal, 'action' => $action];
    }

    /**
     * @param array<string, mixed> $changes  pickup_at?, from?, to?, car? — untrusted tool input
     * @return array{ok: bool, action?: array<string, mixed>, error?: string, booking?: LimoBooking, summary?: array<string, string>}
     */
    public function proposeEditBooking(string $reference, array $changes, User $user): array
    {
        if (! $this->access->allows($user, 'limousine.booking', Permission::Write)) {
            return ['ok' => false, 'error' => 'forbidden'];
        }

        $booking = $this->resolveBooking($reference);
        if ($booking === null) {
            return ['ok' => false, 'error' => 'booking_not_found'];
        }

        if (in_array($booking->status, [LimoBooking::STATUS_CANCELLED, LimoBooking::STATUS_COMPLETED], true)) {
            return ['ok' => false, 'error' => 'not_editable'];
        }

        $pickupAt = null;
        $rawPickup = is_scalar($changes['pickup_at'] ?? null) ? trim((string) $changes['pickup_at']) : '';
        if ($rawPickup !== '') {
            try {
                $pickupAt = CarbonImmutable::parse($rawPickup, 'Asia/Bahrain');
            } catch (Throwable) {
                return ['ok' => false, 'error' => 'bad_date'];
            }
        }

        $from = is_scalar($changes['from'] ?? null) ? trim((string) $changes['from']) : '';
        $to = is_scalar($changes['to'] ?? null) ? trim((string) $changes['to']) : '';
        $car = is_scalar($changes['car'] ?? null) ? trim((string) $changes['car']) : '';

        if ($pickupAt === null && $from === '' && $to === '' && $car === '') {
            return ['ok' => false, 'error' => 'nothing_to_change'];
        }

        return [
            'ok' => true,
            'booking' => $booking,
            'action' => [
                'type' => self::EDIT_BOOKING,
                'booking_id' => $booking->id,
                'pickup_at' => $pickupAt?->format('Y-m-d\TH:i'),
                'from' => $from,
                'to' => $to,
                'car' => $car,
            ],
            'summary' => [
                'pickup_at' => $pickupAt !== null ? $this->when($pickupAt) : '',
                'from' => $from,
                'to' => $to,
                'car' => $car,
            ],
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
            self::EDIT_BOOKING => $this->editBooking($action, $user, $lang),
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

        $tableTotal = $fare->total;
        $overridden = $this->applyOverride($action, $fare, $user);
        if ($overridden === false) {
            return new ActionOutcome(Replies::forbidden($lang));
        }
        $fare = $overridden;

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
        $this->logOverrideIfAny($booking, $fare->total, $tableTotal, $user);

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

        $tableTotal = $fare->total;
        $overridden = $this->applyOverride($action, $fare, $user);
        if ($overridden === false) {
            return new ActionOutcome(Replies::forbidden($lang));
        }
        $fare = $overridden;

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
        $this->logOverrideIfAny($quote, $fare->total, $tableTotal, $user);

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

    /**
     * The proposed fare, with a flat admin price standing in for the total —
     * the discount % no longer applies once a specific figure has been set by
     * hand, so it is zeroed rather than left describing a total it did not
     * produce.
     */
    private function withOverride(FareResult $fare, float $overrideAmount): FareResult
    {
        $overrideAmount = round(max(0.0, $overrideAmount), 3);

        return new FareResult(
            found: true,
            reason: null,
            serviceId: $fare->serviceId,
            serviceEn: $fare->serviceEn,
            serviceAr: $fare->serviceAr,
            carId: $fare->carId,
            carEn: $fare->carEn,
            carAr: $fare->carAr,
            optionCode: $fare->optionCode,
            optionEn: $fare->optionEn,
            optionAr: $fare->optionAr,
            hours: $fare->hours,
            roundTrip: $fare->roundTrip,
            extraHours: $fare->extraHours,
            base: $overrideAmount,
            extraHoursAmount: 0.0,
            discountPercent: 0.0,
            discount: 0.0,
            total: $overrideAmount,
        );
    }

    /**
     * Re-applies an action's stored override at EXECUTE time — never trusting
     * that the propose-time admin check still holds (an account could have
     * been demoted in between). Returns `false` when it no longer may.
     *
     * @param array<string, mixed> $action
     */
    private function applyOverride(array $action, FareResult $fare, User $user): FareResult|false
    {
        if (! isset($action['override_amount']) || ! is_numeric($action['override_amount'])) {
            return $fare;
        }

        if (! $user->isAdmin()) {
            return false;
        }

        return $this->withOverride($fare, (float) $action['override_amount']);
    }

    private function logOverrideIfAny(Model $subject, float $charged, float $tableTotal, User $user): void
    {
        if (abs($charged - $tableTotal) < 0.0005) {
            return;
        }

        $this->activity->logFor(
            $subject,
            'updated',
            'Admin price override via the WhatsApp assistant: ' . $this->money($charged) . ' (table price ' . $this->money($tableTotal) . ')',
            $user,
        );
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim((string) $value), 'Asia/Bahrain')->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $action
     */
    private function editBooking(array $action, User $user, string $lang): ActionOutcome
    {
        if (! $this->access->allows($user, 'limousine.booking', Permission::Write)) {
            return new ActionOutcome(Replies::forbidden($lang));
        }

        $booking = LimoBooking::query()->find((int) ($action['booking_id'] ?? 0));
        $leg = $booking?->legs()->orderBy('sequence')->first();
        if ($booking === null || ! $leg instanceof LimoLeg) {
            return new ActionOutcome(Replies::bookingNotFound($lang));
        }

        if (in_array($booking->status, [LimoBooking::STATUS_CANCELLED, LimoBooking::STATUS_COMPLETED], true)) {
            return new ActionOutcome(Replies::bookingNotEditable($lang));
        }

        $before = [
            'pickup_at' => $leg->start_at?->toIso8601String(),
            'from' => $leg->from_location,
            'to' => $leg->to_location,
            'car' => $leg->vehicle_details,
        ];

        $pickupRaw = is_scalar($action['pickup_at'] ?? null) ? trim((string) $action['pickup_at']) : '';
        if ($pickupRaw !== '') {
            try {
                $leg->start_at = Carbon::instance(CarbonImmutable::parse($pickupRaw, 'Asia/Bahrain')->toDateTime());
            } catch (Throwable) {
                // The proposal already validated this; an unparsable value here is unreachable.
            }
        }

        $from = is_scalar($action['from'] ?? null) ? trim((string) $action['from']) : '';
        if ($from !== '') {
            $leg->from_location = $from;
        }

        $to = is_scalar($action['to'] ?? null) ? trim((string) $action['to']) : '';
        if ($to !== '') {
            $leg->to_location = $to;
        }

        $car = is_scalar($action['car'] ?? null) ? trim((string) $action['car']) : '';
        if ($car !== '') {
            $leg->vehicle_details = $car;
        }

        $leg->save();

        $this->activity->logFor(
            $booking,
            'updated',
            'Edited via the WhatsApp assistant — was: ' . json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $user,
        );

        return new ActionOutcome(Replies::bookingEdited($lang, (string) $booking->reference));
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
