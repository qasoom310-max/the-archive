<?php

declare(strict_types=1);

namespace App\Erp\Mail;

use App\Erp\Settings\Setting;

/**
 * The wording and look of every email a customer receives, editable per
 * database on Settings → Customer emails.
 *
 * Two parts:
 *
 * - the LAYOUT shared by every email: colours, greeting, closing line,
 *   signature, footer and small print;
 * - one TEMPLATE per kind of email (quotation, invoice, …): subject, heading,
 *   the text above the summary box and the text below it.
 *
 * Everything is stored as one JSON row (`email.templates`). A blank field
 * means "use the default", so an untouched database sends exactly what it
 * always did, and clearing a box is how a field is reset. Text may carry
 * {placeholders} filled from the record being sent; an unknown one is left
 * as typed so the mistake shows in the preview rather than vanishing.
 */
final class EmailTemplates
{
    public const SETTING = 'email.templates';

    /** @var list<string> fields of one email kind */
    public const FIELDS = ['subject', 'heading', 'intro', 'outro', 'button'];

    /** @var list<string> fields of the shared layout */
    public const LAYOUT_FIELDS = ['accent', 'footer_bg', 'show_logo', 'greeting', 'closing', 'signature', 'footer', 'small_print'];

    /** Placeholders every email understands. */
    public const COMMON = ['customer', 'company', 'phone', 'email', 'website', 'address'];

    /**
     * Every kind of customer email: what it is, which app sends it, the
     * placeholders it knows, and its default wording.
     *
     * @var array<string, array{label: string, description: string, apps: list<string>, placeholders: list<string>, subject: string, heading: string, intro: string, outro: string, button?: string, extras?: array<string, array{label: string, default: string}>}>
     */
    public const TYPES = [
        'quotation' => [
            'label' => 'Quotation',
            'description' => 'Sent with a new quotation, and when a quotation is emailed from the quotations list.',
            'apps' => ['limousine', 'rental'],
            'placeholders' => ['reference', 'total', 'valid_until'],
            'subject' => '{company} — Quotation {reference}',
            'heading' => 'Your quotation {reference}',
            'intro' => 'Thank you for your interest in our services. Please find your quotation attached.',
            'outro' => 'To confirm the booking, simply reply to this email or call us, and we will take care of the rest.',
        ],
        'invoice' => [
            'label' => 'Invoice',
            'description' => 'Sent with a new invoice. {payment_status} becomes one of the three lines below, depending on what has been paid.',
            'apps' => ['limousine', 'rental'],
            'placeholders' => ['reference', 'total', 'paid', 'balance', 'payment_status'],
            'subject' => '{company} — Invoice {reference}',
            'heading' => 'Your invoice {reference}',
            'intro' => "Thank you for choosing us. Please find your invoice attached.\n\n{payment_status}",
            'outro' => '',
            'extras' => [
                'status_paid' => ['label' => 'When paid in full', 'default' => 'This invoice is paid in full — your receipt is attached as well.'],
                'status_part' => ['label' => 'When part paid', 'default' => 'We have received part of the payment — your receipt is attached. The remaining balance is shown below.'],
                'status_unpaid' => ['label' => 'When nothing is paid yet', 'default' => 'The amount due is shown below. Payment details are printed on the invoice.'],
            ],
        ],
        'receipt' => [
            'label' => 'Payment receipt',
            'description' => 'Sent when a payment is received, and when a receipt is emailed from the receipts list.',
            'apps' => ['limousine', 'rental'],
            'placeholders' => ['reference', 'amount', 'invoice', 'balance'],
            'subject' => '{company} — Receipt {reference}',
            'heading' => 'Payment received — thank you',
            'intro' => 'We have received your payment of {amount}. Your receipt is attached.',
            'outro' => '',
        ],
        'driver_assigned' => [
            'label' => 'Driver assigned',
            'description' => 'Sent when a car and a driver are put on a limousine trip.',
            'apps' => ['limousine'],
            'placeholders' => ['reference', 'pickup_time', 'driver', 'driver_phone', 'car', 'plate'],
            'subject' => '{company} — Your driver for trip {reference}',
            'heading' => 'Your driver is assigned',
            'intro' => 'Your driver and car for this trip are below. The driver will be waiting at the pick-up point on time.',
            'outro' => 'If anything changes, reply to this email or call us.',
        ],
        'coupon' => [
            'label' => 'Credit coupon',
            'description' => 'Sent with a credit coupon when a paid trip is cancelled.',
            'apps' => ['limousine'],
            'placeholders' => ['code', 'value', 'valid_until'],
            'subject' => '{company} — Your credit coupon {code}',
            'heading' => 'Your credit coupon',
            'intro' => 'We are sorry your trip could not go ahead. The amount you paid is held for you as credit, ready to use on a future booking.',
            'outro' => "Quote this code when you book and the value comes off your fare. You can use it across more than one trip until it runs out.\n\nYour coupon is attached for your records.",
        ],
        'service_order' => [
            'label' => 'Service order to sign',
            'description' => 'Sent to a private customer after the trip, asking them to sign the service order.',
            'apps' => ['limousine'],
            'placeholders' => ['reference'],
            'subject' => '{company} — Service Order {reference}',
            'heading' => 'Service Order {reference}',
            'intro' => 'Thank you for travelling with us. Please confirm the trip below by signing your service order.',
            'outro' => 'By signing you confirm the driver arrived and the service was provided.',
            'button' => 'Review & sign',
        ],
        'service_order_company' => [
            'label' => 'Service completed (company)',
            'description' => 'Sent to a company customer after the trip, as their record that it was done.',
            'apps' => ['limousine'],
            'placeholders' => ['reference'],
            'subject' => '{company} — Service completed — {reference}',
            'heading' => 'Service Order {reference}',
            'intro' => 'This is to confirm that our driver has reached your customer and the service below has been completed.',
            'outro' => 'No action is needed. Please keep this as your record of the trip.',
        ],
        'agreement' => [
            'label' => 'Car hire agreement',
            'description' => 'Sent with the car hire agreement from a rental order.',
            'apps' => ['rental'],
            'placeholders' => ['reference', 'car', 'pickup_date', 'return_date'],
            'subject' => '{company} — Car Hire Agreement — {reference}',
            'heading' => 'Your car hire agreement {reference}',
            'intro' => 'Please find your car hire agreement attached ({reference}).',
            'outro' => 'Kindly review, sign, and return it. Thank you for choosing us.',
        ],
    ];

    /** @var array<string, string> */
    public const LAYOUT_DEFAULTS = [
        'accent' => '#FFC837',
        'footer_bg' => '#111827',
        'show_logo' => '1',
        'greeting' => 'Dear {customer},',
        'closing' => 'If you have any questions, simply reply to this email.',
        'signature' => "Kind regards,\n**{company}**",
        'footer' => "**{company}**\n{phone} · {website}\n{address}",
        'small_print' => '',
    ];

    /**
     * What is stored for this database: only the fields someone typed.
     *
     * @return array{layout: array<string, string>, types: array<string, array<string, string>>}
     */
    public function stored(): array
    {
        $raw = Setting::get(self::SETTING);
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $data = is_array($data) ? $data : [];

        $layout = [];
        foreach ((array) ($data['layout'] ?? []) as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $layout[$key] = $value;
            }
        }

        $types = [];
        foreach ((array) ($data['types'] ?? []) as $type => $fields) {
            if (! is_string($type) || ! is_array($fields)) {
                continue;
            }
            foreach ($fields as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $types[$type][$key] = $value;
                }
            }
        }

        return ['layout' => $layout, 'types' => $types];
    }

    /**
     * Save what the editor holds. A field equal to its default, or blank, is
     * not stored, so a later change to a default still reaches it.
     *
     * @param  array<string, string>                 $layout
     * @param  array<string, array<string, string>>  $types
     */
    public function save(array $layout, array $types): void
    {
        $out = ['layout' => [], 'types' => []];

        foreach (self::LAYOUT_FIELDS as $field) {
            $value = $this->clean($layout[$field] ?? '');
            if ($value !== '' && $value !== self::LAYOUT_DEFAULTS[$field]) {
                $out['layout'][$field] = $value;
            }
        }

        foreach (self::TYPES as $type => $def) {
            foreach ($this->editableFields($type) as $field) {
                $value = $this->clean($types[$type][$field] ?? '');
                if ($value !== '' && $value !== $this->defaultFor($type, $field)) {
                    $out['types'][$type][$field] = $value;
                }
            }
        }

        Setting::set(self::SETTING, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * The fields one kind of email offers in the editor.
     *
     * @return list<string>
     */
    public function editableFields(string $type): array
    {
        $def = self::TYPES[$type] ?? null;
        if ($def === null) {
            return [];
        }

        $fields = ['subject', 'heading', 'intro', 'outro'];
        if (isset($def['button'])) {
            $fields[] = 'button';
        }

        return [...$fields, ...array_keys($def['extras'] ?? [])];
    }

    public function defaultFor(string $type, string $field): string
    {
        $def = self::TYPES[$type] ?? null;
        if ($def === null) {
            return '';
        }

        if (isset($def['extras'][$field])) {
            return $def['extras'][$field]['default'];
        }

        $value = $def[$field] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * The layout as it applies now: stored values over the defaults.
     *
     * @return array<string, string>
     */
    public function layout(): array
    {
        return $this->mergeLayout($this->stored()['layout']);
    }

    /**
     * @param  array<string, string>  $over
     * @return array<string, string>
     */
    public function mergeLayout(array $over): array
    {
        $layout = self::LAYOUT_DEFAULTS;
        foreach (self::LAYOUT_FIELDS as $field) {
            $value = trim($over[$field] ?? '');
            if ($value !== '') {
                $layout[$field] = $value;
            }
        }

        foreach (['accent', 'footer_bg'] as $colour) {
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $layout[$colour]) !== 1) {
                $layout[$colour] = self::LAYOUT_DEFAULTS[$colour];
            }
        }

        return $layout;
    }

    /**
     * One kind of email's wording as it applies now.
     *
     * @param  array<string, string>|null  $over  unsaved editor values (the preview), else what is stored
     * @return array<string, string>
     */
    public function template(string $type, ?array $over = null): array
    {
        $over ??= $this->stored()['types'][$type] ?? [];

        $out = [];
        foreach ($this->editableFields($type) as $field) {
            $value = trim($over[$field] ?? '');
            $out[$field] = $value !== '' ? $value : $this->defaultFor($type, $field);
        }

        return $out;
    }

    /**
     * Fill one kind of email for a real record.
     *
     * @param  array<string, string>       $vars  placeholder → value (common ones are added)
     * @param  array<string, string>|null  $over  unsaved editor values (the preview)
     * @return array{subject: string, heading: string, intro: list<string>, outro: list<string>, button: string}
     */
    public function compose(string $type, array $vars, ?array $over = null): array
    {
        $tpl = $this->template($type, $over);
        $vars = $this->withCommon($vars);

        // The invoice's payment line is itself a template: pick it, fill it,
        // then hand it to the body as {payment_status}.
        if (isset($vars['payment_state'], $tpl['status_' . $vars['payment_state']])) {
            $vars['payment_status'] = $this->fill($tpl['status_' . $vars['payment_state']], $vars);
        }
        unset($vars['payment_state']);

        return [
            'subject' => trim((string) preg_replace('/\s+/', ' ', $this->fill($tpl['subject'], $vars))),
            'heading' => $this->fill($tpl['heading'], $vars),
            'intro' => self::paragraphs($this->fill($tpl['intro'], $vars)),
            'outro' => self::paragraphs($this->fill($tpl['outro'], $vars)),
            'button' => $this->fill($tpl['button'] ?? '', $vars),
        ];
    }

    /**
     * The common placeholders, plus the ones given (which win).
     *
     * @param  array<string, string>  $vars
     * @return array<string, string>
     */
    public function withCommon(array $vars): array
    {
        $name = Setting::get('company.name');

        return [
            'company' => is_string($name) && trim($name) !== '' ? trim($name) : (string) config('app.name'),
            'phone' => trim((string) Setting::get('company.phone', '')),
            'email' => trim((string) (Setting::get('mail.from_address', '') ?: Setting::get('company.email', ''))),
            'website' => trim((string) Setting::get('company.website', '')),
            'address' => trim((string) Setting::get('company.address', '')),
            'customer' => '',
            ...$vars,
        ];
    }

    /**
     * @param  array<string, string>  $vars
     */
    public function fill(string $text, array $vars): string
    {
        $pairs = [];
        foreach ($vars as $key => $value) {
            $pairs['{' . $key . '}'] = $value;
        }

        return strtr($text, $pairs);
    }

    /**
     * Blank lines split paragraphs; a single line break stays inside one.
     *
     * @return list<string>
     */
    public static function paragraphs(string $text): array
    {
        $parts = preg_split('/\R\s*\R/', trim(str_replace("\r\n", "\n", $text))) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));
    }

    /**
     * Text for the HTML email: escaped, **bold** honoured, line breaks kept.
     */
    public static function html(string $text): string
    {
        $safe = e($text);
        $safe = (string) preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $safe);

        return nl2br($safe, false);
    }

    /**
     * Lines of the signature or footer: blank ones and ones left holding only
     * a separator (a "{phone} · {website}" line with neither set) are dropped.
     *
     * @return list<string>
     */
    public static function lines(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/^[\s·|]+|[\s·|]+$/u', '', $line));
            $line = (string) preg_replace('/\s*·\s*(·\s*)+/u', ' · ', $line);
            if ($line !== '' && $line !== '****') {
                $out[] = $line;
            }
        }

        return $out;
    }

    /** Dark text on a light colour, white on a dark one. */
    public static function inkOn(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) {
            return '#111827';
        }
        [$r, $g, $b] = array_map(static fn (string $c): int => (int) hexdec($c), str_split($hex, 2));

        return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 150 ? '#111827' : '#ffffff';
    }

    private function clean(string $value): string
    {
        return trim(str_replace("\r\n", "\n", $value));
    }
}
