<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Assistant;

use App\Erp\Activity\ActivityLogger;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\WhatsApp\Assistant\Brain\Brain;
use Modules\WhatsApp\Models\AssistantConfiguration;
use Modules\WhatsApp\Models\AssistantStaff;
use Modules\WhatsApp\Models\Conversation;
use Modules\WhatsApp\Models\ConversationMessage;
use Throwable;

/**
 * Handles one inbound WhatsApp message from a staff member, end to end.
 *
 * The order of checks is the security model:
 *  1. dedupe on Meta's message id (a redelivery does nothing);
 *  2. kill-switch off → silence;
 *  3. unknown number → one "not authorised" reply, then silence;
 *  4. a pending proposal + an explicit YES → execute it, as the mapped user;
 *  5. otherwise → the AI, which can read and PROPOSE but never create.
 *
 * Deciding YES is done here, by code, not by the model — so "no booking, PDF
 * or payment link without an explicit confirmation" does not rest on a prompt.
 */
final class StaffAssistant
{
    /** Tool rounds allowed in one turn before giving up. */
    private const MAX_ROUNDS = 6;

    private const YES = '/^\s*(yes|y|yeah|yep|ok|okay|confirm|confirmed|go ahead|sure|نعم|ايوه|أيوه|ايوا|اي|إي|أكيد|اكيد|تمام|موافق|اوكي|أوكي)\s*[.!✅👍]*\s*$/iu';

    private const NO = '/^\s*(no|n|nope|cancel|stop|لا|كنسل|إلغاء|الغاء|ألغ|الغ|وقف)\s*[.!]*\s*$/iu';

    public function __construct(
        private readonly Brain $brain,
        private readonly MetaMessenger $messenger,
        private readonly AssistantActions $actions,
        private readonly ActivityLogger $activity,
    ) {
    }

    public function handle(string $from, string $messageId, string $type, string $text): void
    {
        if (! $this->claim($messageId, $type, $text)) {
            return; // already processed
        }

        $config = AssistantConfiguration::current();
        if (! (bool) $config->enabled) {
            return; // kill-switch
        }

        $waId = AssistantStaff::normalise($from);
        $staff = AssistantStaff::forNumber($waId);
        $conversation = Conversation::query()->firstOrNew(['wa_id' => $waId]);
        $lang = Replies::detectLanguage($text, $conversation->exists ? $conversation->language : 'en');

        if ($staff === null) {
            $this->refuse($conversation, $lang);

            return;
        }

        $user = User::query()->find($staff->user_id);
        if (! $user instanceof User || $user->isPaused()) {
            $this->refuse($conversation, $lang);

            return;
        }

        if ($conversation->state === 'unauthorized') {
            $conversation->state = null; // the number has since been authorised
        }
        $conversation->user_id = $user->id;
        $conversation->language = $lang;
        $conversation->last_msg_at = Carbon::now();
        $conversation->save();
        $this->linkMessage($messageId, $conversation->id);

        if ($type !== 'text' || trim($text) === '') {
            $this->say($conversation, Replies::unsupportedType($lang));

            return;
        }

        Auth::setUser($user);

        try {
            $this->converse($conversation, $user, $config, trim($text));
        } catch (Throwable $e) {
            Log::error('WhatsApp assistant failed', ['error' => $e->getMessage(), 'conversation' => $conversation->id]);
            $this->say($conversation, Replies::failed($lang));
        } finally {
            Auth::forgetUser();
        }
    }

    private function converse(Conversation $conversation, User $user, AssistantConfiguration $config, string $text): void
    {
        $lang = $conversation->language;
        $pending = $conversation->pendingAction();

        if ($pending !== null && preg_match(self::YES, $text) === 1) {
            $conversation->clearPending();
            $conversation->remember('user', $text);
            $conversation->save();

            $this->runConfirmed($conversation, $user, $pending);

            return;
        }

        if ($pending !== null && preg_match(self::NO, $text) === 1) {
            $conversation->clearPending();
            $conversation->remember('user', $text);
            $conversation->save();
            $this->say($conversation, Replies::cancelled($lang));

            return;
        }

        // Anything else replaces a pending proposal: the staff member is
        // changing it, and the old version must not be confirmable any more.
        if ($pending !== null) {
            $conversation->clearPending();
            $conversation->save();
        }

        if (! $config->isReady()) {
            $this->say($conversation, Replies::unavailable($lang));

            return;
        }

        $this->messenger->sendText($conversation->wa_id, Replies::oneMoment($lang), $conversation->id);
        $this->think($conversation, $user, $config, $text);
    }

    /**
     * @param array<string, mixed> $action
     */
    private function runConfirmed(Conversation $conversation, User $user, array $action): void
    {
        $outcome = $this->actions->execute($action, $user, $conversation);

        if ($outcome->pdf !== null) {
            $sent = $this->messenger->sendDocument($conversation->wa_id, $outcome->pdf, $outcome->filename, $outcome->text, $conversation->id);
            if (! $sent) {
                $this->say($conversation, Replies::failed($conversation->language));

                return;
            }
            $conversation->remember('assistant', $outcome->text);
            $conversation->save();

            return;
        }

        $this->say($conversation, $outcome->text);
    }

    private function think(Conversation $conversation, User $user, AssistantConfiguration $config, string $text): void
    {
        $messages = [];
        foreach ($conversation->history ?? [] as $turn) {
            $messages[] = ['role' => $turn['role'] === 'assistant' ? 'assistant' : 'user', 'content' => $turn['text']];
        }
        // The API wants the conversation to open with the user.
        while ($messages !== [] && $messages[0]['role'] !== 'user') {
            array_shift($messages);
        }
        $messages[] = ['role' => 'user', 'content' => $text];

        $conversation->remember('user', $text);
        $conversation->save();

        $system = AssistantTools::systemPrompt(
            (string) $user->name,
            CarbonImmutable::now('Asia/Bahrain')->format('l j F Y, H:i'),
        );
        $tools = AssistantTools::definitions();

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $reply = $this->brain->respond((string) $config->ai_api_key, $config->model(), $system, $messages, $tools);

            if ($reply->stopReason === 'refusal') {
                $this->say($conversation, Replies::failed($conversation->language));

                return;
            }

            if (! $reply->wantsTools()) {
                $this->say($conversation, $reply->text !== '' ? $reply->text : Replies::greeting($conversation->language));

                return;
            }

            $messages[] = ['role' => 'assistant', 'content' => $reply->content];
            $results = [];

            foreach ($reply->toolCalls as $call) {
                [$result, $confirmation] = $this->runTool($conversation, $user, $call['name'], $call['input']);

                if ($confirmation !== null) {
                    // A proposal is queued: the fixed confirmation text is the
                    // whole reply. The turn ends here, waiting for YES.
                    $this->say($conversation, $confirmation);

                    return;
                }

                $results[] = [
                    'type' => 'tool_result',
                    'toolUseID' => $call['id'],
                    'content' => (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }

            $messages[] = ['role' => 'user', 'content' => $results];
        }

        $this->say($conversation, Replies::failed($conversation->language));
    }

    /**
     * Run one tool call. Returns the result for the model, and — when a
     * proposal was queued — the confirmation text to send instead.
     *
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    private function runTool(Conversation $conversation, User $user, string $name, array $input): array
    {
        $lang = $conversation->language;
        $str = static fn (string $key): string => is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';

        switch ($name) {
            case 'get_services':
                return [['services' => app(\App\Erp\Pricing\FareCalculator::class)->catalogue()], null];

            case 'get_fare':
                $fare = $this->actions->fare($input);
                if (($fare['found'] ?? false) === true) {
                    $this->activity->log('quoted', 'WhatsApp assistant', 'Fare ' . $fare['amount_text'] . ' — ' . $fare['service'] . ' / ' . $fare['option'] . ' / ' . $fare['car'], $user);
                }

                return [$fare, null];

            case 'find_booking':
                return [$this->actions->findBooking($str('reference')), null];

            case 'propose_booking':
            case 'propose_quotation':
                $type = $name === 'propose_booking' ? AssistantActions::BOOKING : AssistantActions::QUOTATION;
                $trip = TripRequest::fromInput($input);
                $proposal = $this->actions->proposeTrip($type, $trip, $user);
                if (! $proposal['ok'] || ! isset($proposal['action'], $proposal['fare'])) {
                    return [$this->proposalError($proposal['error'] ?? 'failed'), null];
                }

                $conversation->propose($proposal['action']);
                $conversation->save();
                $fare = $proposal['fare'];
                $fields = [
                    'car' => $lang === 'ar' ? $fare->carAr : $fare->carEn,
                    'service' => $lang === 'ar' ? $fare->serviceAr : $fare->serviceEn,
                    'option' => $lang === 'ar' ? $fare->optionAr : $fare->optionEn,
                    'direction' => $fare->roundTrip ? ($lang === 'ar' ? ' · ذهاب وعودة' : ' · return trip') : '',
                    'from' => $trip->from,
                    'to' => $trip->to !== '' ? $trip->to : '—',
                    'datetime' => $this->actions->when($trip->pickupAt),
                    'amount' => $this->actions->money($fare->total) . ($fare->discount > 0 ? ' (−' . rtrim(rtrim(number_format($fare->discountPercent, 2), '0'), '.') . '%)' : ''),
                    'customer_name' => $trip->customerName,
                    'customer_phone' => $trip->customerPhone,
                ];

                return [['queued' => true], $type === AssistantActions::BOOKING ? Replies::confirmBooking($lang, $fields) : Replies::confirmQuotation($lang, $fields)];

            case 'propose_document':
                $proposal = $this->actions->proposeDocument($str('reference'), $str('document'), $user);
                if (! $proposal['ok'] || ! isset($proposal['action'], $proposal['booking'])) {
                    return [$this->proposalError($proposal['error'] ?? 'failed'), null];
                }
                $conversation->propose($proposal['action']);
                $conversation->save();

                return [['queued' => true], Replies::confirmDocument($lang, $str('document'), (string) $proposal['booking']->reference)];

            case 'propose_payment_link':
                $amount = is_numeric($input['amount'] ?? null) ? (float) $input['amount'] : null;
                $proposal = $this->actions->proposePaymentLink($str('reference'), $amount, $user);
                if (! $proposal['ok'] || ! isset($proposal['action'], $proposal['booking'])) {
                    return [$this->proposalError($proposal['error'] ?? 'failed'), null];
                }
                $conversation->propose($proposal['action']);
                $conversation->save();
                $booking = $proposal['booking'];

                return [['queued' => true], Replies::confirmPaymentLink(
                    $lang,
                    (string) $booking->reference,
                    (string) ($booking->customer->name ?? $booking->pax_name ?? ''),
                    $this->actions->money((float) $proposal['action']['amount']),
                )];
        }

        return [['error' => 'unknown_tool'], null];
    }

    /**
     * @return array<string, mixed>
     */
    private function proposalError(string $error): array
    {
        $instruction = match (true) {
            $error === 'forbidden' => "The staff member's ERP permissions do not allow this. Tell them so; do not retry.",
            $error === 'no_fare' => 'There is no set fare for this trip. Tell the staff member exactly that; never estimate.',
            $error === 'booking_not_found' => 'No booking matches that number. Ask for the booking or trip number.',
            $error === 'nothing_owed' => 'Nothing is owed on that booking, so no payment link is needed. Say so.',
            $error === 'portal_off' => 'The payment portal is switched off for this database. Say so.',
            str_starts_with($error, 'missing:') => 'Details are missing: ' . substr($error, 8) . '. Ask the staff member for them in one short message.',
            default => 'That could not be prepared. Tell the staff member briefly.',
        };

        return ['ok' => false, 'error' => $error, 'instruction' => $instruction];
    }

    /**
     * Record the inbound message, or report that it was already seen. The
     * unique index on `wa_message_id` is what makes a Meta redelivery a no-op,
     * even when two deliveries race.
     */
    private function claim(string $messageId, string $type, string $text): bool
    {
        if ($messageId === '') {
            return false;
        }

        if (ConversationMessage::query()->where('wa_message_id', $messageId)->exists()) {
            return false;
        }

        try {
            ConversationMessage::query()->create([
                'direction' => ConversationMessage::IN,
                'wa_message_id' => $messageId,
                'type' => $type,
                'body' => $text,
            ]);
        } catch (QueryException) {
            return false; // lost the race to a parallel redelivery
        }

        return true;
    }

    private function linkMessage(string $messageId, int $conversationId): void
    {
        ConversationMessage::query()->where('wa_message_id', $messageId)->update(['conversation_id' => $conversationId]);
    }

    private function refuse(Conversation $conversation, string $lang): void
    {
        // One reply, ever, per unknown number — then silence.
        if ($conversation->exists && $conversation->state === 'unauthorized') {
            return;
        }

        $conversation->language = $lang;
        $conversation->user_id = null;
        $conversation->state = 'unauthorized';
        $conversation->last_msg_at = Carbon::now();
        $conversation->save();

        $this->messenger->sendText($conversation->wa_id, Replies::unauthorized($lang), $conversation->id);
    }

    private function say(Conversation $conversation, string $text): void
    {
        $this->messenger->sendText($conversation->wa_id, $text, $conversation->id);
        $conversation->remember('assistant', $text);
        $conversation->save();
    }
}
