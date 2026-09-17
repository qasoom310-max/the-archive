<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Assistant;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Modules\Limousine\Events\LimoPaymentLinkPaid;
use Modules\Limousine\Models\LimoBooking;
use Modules\Limousine\Models\LimoPaymentLink;
use Modules\WhatsApp\Models\AssistantPaymentLink;
use Modules\WhatsApp\Models\Conversation;

/**
 * "Paid ✅" back to the staff member who raised a payment link from WhatsApp.
 *
 * Runs inside the payment callback's database (it re-enters the right
 * workspace before settling), so the tables it reads are that database's.
 * A link raised on the bookings screen has no assistant row and is ignored.
 */
final class NotifyStaffOfPayment
{
    public function __construct(private readonly MetaMessenger $messenger)
    {
    }

    public function handle(LimoPaymentLinkPaid $event): void
    {
        if (! Schema::hasTable('whatsapp_assistant_payment_links')) {
            return;
        }

        $row = AssistantPaymentLink::query()->where('payment_link_id', $event->paymentLinkId)->first();
        if ($row === null || $row->notified_at !== null) {
            return;
        }

        $conversation = Conversation::query()->find($row->conversation_id);
        $link = LimoPaymentLink::query()->find($event->paymentLinkId);
        $booking = $link !== null ? LimoBooking::query()->find($link->booking_id) : null;
        if ($conversation === null || $booking === null) {
            return;
        }

        $lang = $conversation->language;
        $reference = (string) $booking->reference;

        $text = $booking->balanceDue() <= 0
            ? Replies::paid($lang, $reference)
            : Replies::partPaid(
                $lang,
                $reference,
                app(AssistantActions::class)->money((float) $link->amount),
                app(AssistantActions::class)->money($booking->balanceDue()),
            );

        $this->messenger->sendText($conversation->wa_id, $text, $conversation->id);

        $row->notified_at = Carbon::now();
        $row->save();
    }
}
