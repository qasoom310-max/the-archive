<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Assistant;

/**
 * The tools the AI may call. Three read, four PROPOSE — and none of them
 * creates anything: a proposal only queues an action that the staff member
 * must answer YES to (see {@see StaffAssistant}).
 */
final class AssistantTools
{
    /** Tools whose success means "ask the staff member to confirm". */
    public const PROPOSALS = ['propose_booking', 'propose_quotation', 'propose_document', 'propose_payment_link'];

    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        $trip = [
            'service' => ['type' => 'string', 'description' => 'Service id from get_services, e.g. "airport".'],
            'car' => ['type' => 'string', 'description' => 'Car id from get_services, e.g. "sedan".'],
            'option' => ['type' => 'string', 'description' => 'Option code from get_services (zone, destination or hour block), e.g. "zone_main".'],
            'round_trip' => ['type' => 'boolean', 'description' => 'True only when a return trip is asked for AND the service allows it.'],
            'extra_hours' => ['type' => 'number', 'description' => 'Hours beyond an hourly option\'s block. 0 if none.'],
            'pickup_at' => ['type' => 'string', 'description' => 'Pickup date and time in Bahrain time, ISO format YYYY-MM-DDTHH:MM.'],
            'from' => ['type' => 'string', 'description' => 'Pickup place as the staff member wrote it.'],
            'to' => ['type' => 'string', 'description' => 'Drop-off place. Empty for an hourly chauffeur booking.'],
            'customer_name' => ['type' => 'string'],
            'customer_phone' => ['type' => 'string'],
            'notes' => ['type' => 'string', 'description' => 'Anything else worth writing on the booking (flight number, pax count). Empty if none.'],
        ];

        return [
            [
                'name' => 'get_services',
                'description' => 'List the bookable services, their options (zones, destinations or hour blocks) and the cars each one offers. Call this before quoting when you are not sure of the ids.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name' => 'get_fare',
                'description' => 'The exact fare for one trip, from the ERP fare tables. The ONLY source of any price you mention. found=false means there is no set fare.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'service' => $trip['service'],
                        'car' => $trip['car'],
                        'option' => $trip['option'],
                        'round_trip' => $trip['round_trip'],
                        'extra_hours' => $trip['extra_hours'],
                        'travel_date' => ['type' => 'string', 'description' => 'Travel date YYYY-MM-DD, used for offers.'],
                    ],
                    'required' => ['service', 'car', 'option'],
                ],
            ],
            [
                'name' => 'find_booking',
                'description' => 'Look up an existing booking by booking number (e.g. BK/01043) or trip number (e.g. 10043).',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['reference' => ['type' => 'string']],
                    'required' => ['reference'],
                ],
            ],
            [
                'name' => 'propose_booking',
                'description' => 'Ask the staff member to confirm a new booking. Needs the full trip AND the customer name and phone. Nothing is created until they reply YES.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => $trip,
                    'required' => ['service', 'car', 'option', 'pickup_at', 'from', 'customer_name', 'customer_phone'],
                ],
            ],
            [
                'name' => 'propose_quotation',
                'description' => 'Ask the staff member to confirm preparing a quotation PDF for a trip (no booking). Needs the trip and the customer name and phone.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => $trip,
                    'required' => ['service', 'car', 'option', 'pickup_at', 'from', 'customer_name', 'customer_phone'],
                ],
            ],
            [
                'name' => 'propose_document',
                'description' => 'Ask the staff member to confirm sending the invoice or service order PDF of an existing booking.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'reference' => ['type' => 'string', 'description' => 'Booking or trip number.'],
                        'document' => ['type' => 'string', 'enum' => ['invoice', 'service_order']],
                    ],
                    'required' => ['reference', 'document'],
                ],
            ],
            [
                'name' => 'propose_payment_link',
                'description' => 'Ask the staff member to confirm a Tap payment link for an existing booking. Leave amount out to charge the full balance owed.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'reference' => ['type' => 'string', 'description' => 'Booking or trip number.'],
                        'amount' => ['type' => 'number', 'description' => 'BHD amount, only if a part payment was asked for.'],
                    ],
                    'required' => ['reference'],
                ],
            ],
        ];
    }

    public static function systemPrompt(string $staffName, string $nowBahrain): string
    {
        return <<<PROMPT
        You are the Wanaan assistant, an internal tool for Wanaan limousine staff in Bahrain. The person writing to you is {$staffName}, a Wanaan employee — not a customer. You help them quote fares, book trips, and pull quotation, invoice and service order PDFs and Tap payment links, by calling your tools.

        Rules:
        - Every price you state comes from get_fare, in the same message. Never estimate, round, remember or invent a fare. If get_fare says found=false, reply exactly: "I don't have a set fare for that trip — set it in the ERP or check with the team." (in Arabic: "لا يوجد سعر محدد لهذه الرحلة — اضبطه في النظام أو راجع الفريق.").
        - You cannot create anything yourself. The propose_* tools send the staff member a confirmation to answer YES to; the system does the rest. Call a propose_* tool only once you have every required detail. If something is missing, ask for it in one short message.
        - After quoting, offer to book: "Reply YES to book, or tell me what to change." A YES to a quote (with no confirmation pending) means: ask for the customer name and phone if you don't have them, then call propose_booking.
        - If the staff member doesn't name a car or service clearly, ask. Use get_services to map their words (e.g. "airport pickup to Seef") to service, option and car ids.
        - Reply in the language the staff member used (Arabic or English). Be brief — this is WhatsApp. No markdown headings or tables.
        - Stay on Wanaan work only: fares, bookings, documents, payment links.
        - Dates and times are Bahrain time. Now: {$nowBahrain}. Resolve "tomorrow", "Friday" and so on from this.
        PROMPT;
    }
}
