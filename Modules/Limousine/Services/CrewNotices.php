<?php

declare(strict_types=1);

namespace Modules\Limousine\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoCrewAlertConfiguration;
use Modules\Limousine\Models\LimoDriver;
use Modules\Limousine\Models\LimoLeg;
use Modules\Limousine\Support\WhatsAppNumber;
use Modules\WhatsApp\Models\WhatsAppConfiguration;
use Modules\WhatsApp\Services\WhatsAppService;
use Throwable;

/**
 * Keeps the promise customer service makes: the customer has their driver and
 * car 12 hours before pick-up.
 *
 * Run every few minutes for each database (routes/console.php). For every trip
 * still to happen whose pick-up is within those 12 hours:
 *
 * - a car AND a driver are on it → the customer is sent them on WhatsApp, once
 *   per pair (a later change of car or driver sends the new details; nothing
 *   else re-sends). Sent to the passenger's number, else the customer's.
 * - one or both missing → the operations numbers are reminded, again every
 *   {@see REMIND_EVERY_MINUTES} minutes until the trip has both or its pick-up
 *   time passes; and the owner is told, once, that the promise was missed.
 *   A booking taken inside the 12 hours could never have met it, so the owner
 *   hears of one only after {@see LATE_BOOKING_GRACE_MINUTES} minutes unsolved.
 *
 * Every message is a Meta-approved template (WhatsApp lets a business start a
 * conversation with nothing else): {@see TEMPLATE_CUSTOMER} for customers,
 * {@see TEMPLATE_REMINDER} for staff, in the template language set under
 * Settings → WhatsApp. Nothing runs until an admin turns it on under
 * Settings → Driver details.
 */
final class CrewNotices
{
    public const TEMPLATE_CUSTOMER = 'limo_driver_details';

    public const TEMPLATE_REMINDER = 'limo_assign_reminder';

    public const REMIND_EVERY_MINUTES = 10;

    public const LATE_BOOKING_GRACE_MINUTES = 60;

    public function __construct(private readonly WhatsAppService $whatsapp)
    {
    }

    /**
     * @return array{customers: int, reminders: int, alerts: int}
     */
    public function run(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $sent = ['customers' => 0, 'reminders' => 0, 'alerts' => 0];

        $config = LimoCrewAlertConfiguration::current();
        $whatsapp = WhatsAppConfiguration::current();
        if (! $config->enabled || ! $whatsapp->isConfigured()) {
            return $sent;
        }

        $language = (string) $whatsapp->template_language !== '' ? (string) $whatsapp->template_language : 'en';

        $legs = LimoLeg::query()
            ->whereMorphedTo('legable', LimoBooking::class)
            ->whereIn('status', LimoLeg::OPEN_STATUSES)
            ->where('start_at', '>', $now)
            ->where('start_at', '<=', $now->copy()->addHours(LimoLeg::CREW_PROMISE_HOURS))
            ->with('legable.customer')
            ->orderBy('start_at')
            ->get();

        foreach ($legs as $leg) {
            $booking = $leg->legable;
            if (! $booking instanceof LimoBooking) {
                continue;
            }

            // One trip's bad data (a deleted car, a malformed number) must not
            // stop the rest from going out.
            try {
                if ($leg->hasCarAndDriver()) {
                    $sent['customers'] += $this->tellCustomer($leg, $booking, $now, $language) ? 1 : 0;

                    continue;
                }

                $sent['reminders'] += $this->remindOperations($leg, $booking, $config, $now, $language);
                $sent['alerts'] += $this->alertOwner($leg, $booking, $config, $now, $language) ? 1 : 0;
            } catch (Throwable $e) {
                Log::warning('Limousine driver-details message failed', ['leg' => $leg->id, 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }

    private function tellCustomer(LimoLeg $leg, LimoBooking $booking, Carbon $now, string $language): bool
    {
        $crew = $leg->crewKey();
        if ($leg->crew_notified_for === $crew) {
            return false;
        }

        $car = $leg->carForCustomer();
        $driver = LimoDriver::query()->find($leg->driver_id);
        if ($car === null || $driver === null) {
            return false;
        }

        // The passenger is the one standing at the kerb; the customer (who may
        // be a company's coordinator) only when no passenger number was given.
        $to = WhatsAppNumber::first((string) $booking->pax_contact);
        if ($to === '') {
            $to = WhatsAppNumber::first((string) ($booking->customer->phone ?? ''));
        }
        if ($to === '') {
            return false;
        }

        $this->whatsapp->sendTemplateMessage(
            to: $to,
            template: self::TEMPLATE_CUSTOMER,
            variables: array_map($this->param(...), [
                trim((string) $booking->pax_name) !== '' ? (string) $booking->pax_name : (string) ($booking->customer->name ?? ''),
                (string) $leg->reference,
                $this->pickupTime($leg, $language),
                (string) $driver->name,
                (string) $driver->phone,
                $car['car'],
                $car['plate'],
            ]),
            languageCode: $language,
        );

        $leg->forceFill(['crew_notified_at' => $now, 'crew_notified_for' => $crew])->saveQuietly();

        return true;
    }

    /** @return int how many numbers were reminded */
    private function remindOperations(LimoLeg $leg, LimoBooking $booking, LimoCrewAlertConfiguration $config, Carbon $now, string $language): int
    {
        // A minute's give, so a run that lands a few seconds early does not
        // push the next reminder out by a whole extra run.
        $since = $now->copy()->subMinutes(self::REMIND_EVERY_MINUTES)->addMinute();
        if ($leg->crew_reminded_at !== null && $leg->crew_reminded_at->gt($since)) {
            return 0;
        }

        $numbers = $config->operationsNumbers();
        if ($numbers === []) {
            return 0;
        }

        $variables = $this->reminderVariables($leg, $booking, $language);
        foreach ($numbers as $number) {
            $this->whatsapp->sendTemplateMessage(
                to: $number,
                template: self::TEMPLATE_REMINDER,
                variables: $variables,
                languageCode: $language,
            );
        }

        $leg->forceFill(['crew_reminded_at' => $now])->saveQuietly();

        return count($numbers);
    }

    private function alertOwner(LimoLeg $leg, LimoBooking $booking, LimoCrewAlertConfiguration $config, Carbon $now, string $language): bool
    {
        if ($leg->crew_escalated_at !== null || $leg->start_at === null) {
            return false;
        }

        $owner = $config->ownerNumber();
        if ($owner === '') {
            return false;
        }

        $deadline = $leg->start_at->copy()->subHours(LimoLeg::CREW_PROMISE_HOURS);
        $grace = ($leg->created_at ?? $now)->copy()->addMinutes(self::LATE_BOOKING_GRACE_MINUTES);
        if ($now->lt($deadline->max($grace))) {
            return false;
        }

        $this->whatsapp->sendTemplateMessage(
            to: $owner,
            template: self::TEMPLATE_REMINDER,
            variables: $this->reminderVariables($leg, $booking, $language),
            languageCode: $language,
        );

        $leg->forceFill(['crew_escalated_at' => $now])->saveQuietly();

        return true;
    }

    /**
     * {{1}} trip, {{2}} pick-up, {{3}} customer, {{4}} what is missing.
     *
     * @return list<string>
     */
    private function reminderVariables(LimoLeg $leg, LimoBooking $booking, string $language): array
    {
        $arabic = str_starts_with($language, 'ar');
        $missing = match (true) {
            ! $leg->hasCar() && $leg->driver_id === null => $arabic ? 'سيارة وسائق' : 'car and driver',
            ! $leg->hasCar() => $arabic ? 'سيارة' : 'car',
            default => $arabic ? 'سائق' : 'driver',
        };

        return array_map($this->param(...), [
            (string) $leg->reference,
            $this->pickupTime($leg, $language),
            (string) ($booking->customer->name ?? ''),
            $missing,
        ]);
    }

    private function pickupTime(LimoLeg $leg, string $language): string
    {
        return $leg->start_at === null
            ? ''
            : $leg->start_at->copy()->locale(str_starts_with($language, 'ar') ? 'ar' : 'en')->isoFormat('ddd D MMM, HH:mm');
    }

    /**
     * A template value as Meta accepts it: one line, and never empty (an empty
     * parameter fails the whole message).
     */
    private function param(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value !== '' ? $value : '-';
    }
}
