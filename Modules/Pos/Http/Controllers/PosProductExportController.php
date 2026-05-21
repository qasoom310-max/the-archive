<?php

declare(strict_types=1);

namespace Modules\Pos\Http\Controllers;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Modules\Pos\Models\PosProduct;

/**
 * Streams the full POS product catalogue as a UTF-8 CSV. The column set
 * is identical to {@see PosProductImportTemplateController} so an export
 * can round-trip back through the importer without manual reshaping.
 *
 * Gated by `pos.product` Read — same surface as the template / list,
 * so cashier ACLs are honoured (POS-user group has Read on pos.product
 * via its ACL, admins always pass).
 */
final class PosProductExportController
{
    public function __invoke(): Response
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.product', Permission::Read);

        $handle = fopen('php://temp', 'r+b');
        if ($handle === false) {
            return new Response('Could not open temp stream.', 500);
        }

        // UTF-8 BOM so Excel auto-detects encoding (matches the template
        // controller's convention — a user opening export.csv after
        // editing template.csv shouldn't see "Café" turn to "CafÃ©").
        fwrite($handle, "\xEF\xBB\xBF");

        // Header — must match the importer's accepted columns verbatim
        // (the importer is tolerant of synonyms, but writing the canonical
        // form here keeps round-trips clean).
        fputcsv($handle, ['Name', 'Barcode', 'Sale Price', 'Cost Price', 'Tax %']);

        // Chunked iteration in case the catalogue grows large — avoids
        // hydrating thousands of models at once. orderBy('name') keeps
        // export output deterministic so diffs between exports are readable.
        PosProduct::query()
            ->orderBy('name')
            ->chunk(500, function ($chunk) use ($handle): void {
                foreach ($chunk as $product) {
                    /** @var PosProduct $product */
                    fputcsv($handle, [
                        $product->name,
                        $product->barcode ?? '',
                        number_format($product->price, 2, '.', ''),
                        number_format($product->cost_price ?? 0.0, 2, '.', ''),
                        number_format($product->tax_rate, 2, '.', ''),
                    ]);
                }
            });

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        $filename = 'pos-products-' . date('Y-m-d') . '.csv';

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
