<?php

declare(strict_types=1);

namespace Modules\Pos\Http\Controllers;

use App\Erp\Security\AccessControl;
use App\Erp\Security\Permission;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Streams an empty .csv with the exact columns the importer expects, plus a
 * single example row so the user can see the value shapes. UTF-8 BOM is
 * prepended so Excel opens it without garbling accented characters.
 *
 * Gated by `pos.product` Read so non-cashiers don't see the schema even
 * though it's hardly secret — keeps the surface consistent with the rest
 * of POS.
 */
final class PosProductImportTemplateController
{
    public function __invoke(): Response
    {
        app(AccessControl::class)->authorize(Auth::user(), 'pos.product', Permission::Read);

        $rows = [
            ['Name', 'Barcode', 'Sale Price', 'Cost Price', 'Tax %'],
            ['Espresso',   '5901234123457', '3.50', '0.90', '15'],
            ['Cappuccino', '5901234123464', '4.50', '1.20', '15'],
        ];

        $handle = fopen('php://temp', 'r+b');
        if ($handle === false) {
            return new Response('Could not open temp stream.', 500);
        }

        // BOM so Excel auto-detects UTF-8.
        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="pos-products-template.csv"',
        ]);
    }
}
