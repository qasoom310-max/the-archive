<?php

declare(strict_types=1);

namespace Modules\Pos\Support;

use App\Erp\Settings\Setting;

/**
 * The receipt printer the register prints to over the network — an Epson TM
 * printer (TM-T20III and friends) addressed by its IP, spoken to with Epson's
 * ePOS-Print protocol straight from the browser. Per database, set on the POS
 * app's own Settings tab.
 *
 * No address = no network printer: the till falls back to the browser's own
 * print, exactly as before.
 */
final class ReceiptPrinter
{
    /** Printable width in dots: an 80 mm roll is 576, a 58 mm roll 384. */
    public const PAPER_WIDTHS = [
        576 => '80 mm',
        384 => '58 mm',
    ];

    /** An IPv4 address or a host name, with an optional port. */
    public const ADDRESS_PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9.\-]{0,251}[A-Za-z0-9])?(?::\d{1,5})?$/';

    public static function address(): string
    {
        return trim((string) Setting::get('pos.printer.ip', ''));
    }

    /** HTTPS by default: an https ERP page may not call a plain-http address. */
    public static function secure(): bool
    {
        return (string) Setting::get('pos.printer.https', '1') !== '0';
    }

    public static function width(): int
    {
        $width = (int) Setting::get('pos.printer.width', '576');

        return array_key_exists($width, self::PAPER_WIDTHS) ? $width : 576;
    }

    public static function save(string $address, bool $secure, int $width): void
    {
        Setting::set('pos.printer.ip', trim($address));
        Setting::set('pos.printer.https', $secure ? '1' : '0');
        Setting::set('pos.printer.width', (string) (array_key_exists($width, self::PAPER_WIDTHS) ? $width : 576));
    }

    /**
     * What the browser needs to print, or null when no printer is set.
     *
     * @return array{url: string, width: int, messages: array<string, mixed>}|null
     */
    public static function config(): ?array
    {
        $address = self::address();
        if ($address === '' || preg_match(self::ADDRESS_PATTERN, $address) !== 1) {
            return null;
        }

        return [
            'url' => (self::secure() ? 'https' : 'http') . '://' . $address
                . '/cgi-bin/epos/service.cgi?devid=local_printer&timeout=10000',
            'width' => self::width(),
            // The browser does the printing, so it carries the wording too.
            'messages' => [
                'unreachable' => __('The receipt printer could not be reached. Check it is on and on the same network, and that this device has opened the printer page once.'),
                'refused' => __('The receipt printer refused the job:'),
                'fallback' => __('Printing through the browser instead.'),
                'test' => [
                    'title' => __('Printer test'),
                    'subtitle' => __('The ERP can print to this printer.'),
                    'total' => __('Total'),
                    'ok' => __('Ready to print receipts.'),
                ],
            ],
        ];
    }
}
