<?php

declare(strict_types=1);

namespace Modules\Pos\Services;

use App\Erp\Money\Currencies;
use App\Erp\Settings\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Imagick;
use Modules\Pos\Models\PosOrder;
use RuntimeException;

/**
 * Renders a paid POS order as a PNG receipt image, suitable for use as
 * the HEADER:IMAGE on the `pos_receipt` WhatsApp template.
 *
 * Pipeline:
 *   1. Blade view `pos::receipt-pdf` → DomPDF generates an in-memory PDF
 *      (pure PHP — no system binaries beyond the PHP runtime).
 *   2. Imagick (with internal Ghostscript) rasterises the PDF's first
 *      (only) page into a PNG. Shell `gs` is disabled on Hostinger but
 *      Imagick links to the GS library directly, so this works there.
 *   3. PNG written to `storage/app/public/whatsapp-receipts/{ref}.png`
 *      and the public URL returned for Meta to fetch.
 *
 * Hostinger Cloud has `imagick` + `gd` enabled per the deploy host
 * fingerprint; if either disappears in the future, throw early with a
 * named exception so the listener can log to Chatter and skip silently
 * instead of breaking checkout.
 */
/**
 * Not `final` — needs to be Mockery-mockable from the WhatsApp receipt
 * test suite (those tests run without Imagick / Ghostscript and stub
 * `render()` to return a known URL). Introducing a bespoke interface
 * for a one-implementation service would be over-engineering.
 */
class PosReceiptImageRenderer
{
    /**
     * Public-disk relative directory where rasterised receipts land.
     * MUST be in lockstep with deploy.yml's rsync `--exclude` list,
     * same contract as the other user-content buckets.
     */
    public const BUCKET = 'whatsapp-receipts';

    /**
     * Render the order to a PNG and return its public URL.
     * Throws RuntimeException if the rendering pipeline isn't available —
     * the WhatsApp listener catches and logs to Chatter without breaking
     * the sale, same pattern as `WhatsAppException`.
     */
    public function render(PosOrder $order): string
    {
        if (! extension_loaded('imagick')) {
            throw new RuntimeException('Imagick PHP extension is required to render receipt images.');
        }

        $data = $this->receiptViewData($order);
        $pdfBinary = Pdf::loadView('pos::receipt-pdf', $data)->output();

        $disk = Storage::disk('public');
        $filename = self::BUCKET . '/' . $this->safeFilename($order) . '.png';

        $disk->put($filename, $this->rasterisePdf($pdfBinary, $disk, $order));

        $url = $disk->url($filename);

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('Failed to derive public URL for rendered receipt.');
        }

        return $url;
    }

    /**
     * Pull the order into a plain-array shape the Blade can render
     * without invoking model magic at PDF-render time. Public so the
     * printable-receipt route ({@see \Modules\Pos\Http\Controllers\PosReceiptPrintController})
     * can reuse the exact same shape the WhatsApp PNG is built from.
     *
     * @return array<string, mixed>
     */
    public function receiptViewData(PosOrder $order): array
    {
        $companyName = (string) Setting::get('company.name', 'OpenERP');

        // DomPDF needs an absolute filesystem path for <img src>, NOT a URL —
        // its HTTP fetcher is disabled (security default). Resolve the logo
        // relative path back to its on-disk location, falling back to null
        // when no logo is configured or the file is gone (same defensive
        // guard `Logo::url()` uses).
        $logoPath = null;
        $logoRel = Setting::get('company.logo');
        if (is_string($logoRel) && $logoRel !== '') {
            $disk = Storage::disk('public');
            if ($disk->exists($logoRel)) {
                $logoPath = (string) $disk->path($logoRel);
            }
        }

        $lines = [];
        foreach ($order->lines as $line) {
            $lines[] = [
                'qty' => rtrim(rtrim(number_format((float) $line->qty, 3), '0'), '.'),
                'name' => (string) $line->name,
                'total' => Currencies::format((float) $line->total),
                'condiments' => array_map(
                    static fn (array $c): string => '+ ' . $c['name']
                        . ($c['price'] > 0 ? ' (' . Currencies::format($c['price']) . ')' : ''),
                    $line->condiments ?? [],
                ),
            ];
        }

        // `$p->method` is the BelongsTo target — Larastan infers it as
        // non-null on the relation property, so the nullsafe `?->name`
        // is flagged as unnecessary. Run a plain `if` for runtime
        // safety (the row could have been deleted) without tripping the
        // static-analysis rule.
        $payments = [];
        foreach ($order->payments as $p) {
            $methodName = '—';
            $method = $p->method;
            if ($method !== null) {
                $methodName = (string) $method->name;
            }
            $payments[] = [
                'method' => $methodName,
                'amount' => Currencies::format((float) $p->amount),
            ];
        }

        return [
            'companyName' => $companyName,
            'logoPath' => $logoPath,
            'orderReference' => (string) $order->reference,
            'orderedAt' => optional($order->ordered_at)->format('M j, Y g:i A') ?? '',
            'customerPhone' => $order->customer_phone,
            // Remote / delivery details — only meaningful on a remote order.
            'customerName' => $order->customer_name,
            'deliveryAddress' => $order->delivery_address,
            'deliveryReference' => $order->delivery_reference,
            'deliveryFee' => $order->delivery_fee > 0 ? Currencies::format((float) $order->delivery_fee) : null,
            'lines' => $lines,
            'subtotal' => Currencies::format((float) $order->subtotal),
            'taxTotal' => Currencies::format((float) $order->tax_total),
            // Open per-phone customer discount — only shown when one applied.
            'customerDiscount' => $order->customer_discount_total > 0
                ? Currencies::format((float) $order->customer_discount_total)
                : null,
            'customerDiscountPercent' => rtrim(rtrim(number_format((float) $order->customer_discount_percent, 2), '0'), '.'),
            'total' => Currencies::format((float) $order->total),
            'payments' => $payments,
            'changeDue' => $order->change_due > 0 ? Currencies::format((float) $order->change_due) : null,
        ];
    }

    /**
     * Convert the DomPDF PDF binary into PNG bytes via Imagick. 200 DPI
     * keeps text crisp on a phone screen without bloating the file
     * (typical output ~80–150 KB, well under WhatsApp's 5 MB image cap).
     */
    private function rasterisePdf(string $pdf, Filesystem $disk, PosOrder $order): string
    {
        $imagick = new Imagick();
        $imagick->setResolution(200, 200);
        $imagick->readImageBlob($pdf);
        $imagick->setIteratorIndex(0); // first (and only) page
        $imagick->setImageBackgroundColor('white');
        $imagick = $imagick->flattenImages(); // bake transparency
        $imagick->setImageFormat('png');
        $imagick->setImageCompressionQuality(90);

        $png = (string) $imagick->getImageBlob();
        $imagick->clear();
        $imagick->destroy();

        // Disk filesystem doesn't need this — we passed it in for future
        // S3 / object-store callers where put() returns metadata we'd
        // forward back to the caller.
        unset($disk, $order);

        return $png;
    }

    /**
     * Receipt references look like `POS/1/0004` — `/` is illegal in
     * filenames on most filesystems. Replace with `-` and strip
     * everything else outside ASCII alphanumerics / dash / underscore
     * so a custom reference format can never break the storage write.
     */
    private function safeFilename(PosOrder $order): string
    {
        $base = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $order->reference);

        return ($base ?: 'order') . '-' . $order->id;
    }
}
