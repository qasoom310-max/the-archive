<?php

declare(strict_types=1);

namespace Modules\Limousine\Http\Controllers;

use App\Erp\Export\Concerns\GuardsExport;
use App\Erp\Export\TabularRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Limousine\Models\LimoReceipt;
use Modules\Limousine\Services\LimoReceiptPdf;
use Modules\Limousine\Services\LimoReceiptRows;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads of the receipts list: CSV, Excel, PDF and a printable view — all
 * four from the SAME filtered rows as {@see LimoReceiptRows} (tab, method,
 * search, date range and who created it all ride along), narrowed to the
 * ticked rows when any are ticked (`?ids=3,7,12`).
 *
 * "PDF" with ticked rows downloads the actual receipt document(s), one page
 * each ({@see LimoReceiptPdf}), the same page the row's download icon gives.
 * Nothing ticked: the plain tabular report of the whole tab, as before.
 */
final class LimoReceiptExportController
{
    use GuardsExport;

    /** A PDF of receipt documents is one DomPDF render: past this it risks the web server's timeout. */
    private const MAX_DOCUMENTS = 100;

    public function __construct(
        private readonly LimoReceiptRows $rows,
        private readonly TabularRenderer $renderer,
        private readonly LimoReceiptPdf $receiptPdf,
    ) {}

    public function csv(Request $request): StreamedResponse
    {
        $this->authorizeExport('limousine.receipt');

        return $this->renderer->csv($this->rows->headings(), $this->rowsFor($request), $this->exportFilename('limousine-receipts'));
    }

    public function excel(Request $request): StreamedResponse
    {
        $this->authorizeExport('limousine.receipt');

        return $this->renderer->excel($this->rows->headings(), $this->rowsFor($request), $this->exportFilename('limousine-receipts'));
    }

    public function pdf(Request $request): Response
    {
        $this->authorizeExport('limousine.receipt');

        $ids = $this->ids($request);
        if ($ids !== [] && ! $request->boolean('live')) {
            return $this->receiptDocuments($ids);
        }

        return $this->renderer->pdf($this->rows->headings(), $this->rowsFor($request), __('Receipts'), $this->exportFilename('limousine-receipts'));
    }

    public function print(Request $request): View
    {
        $this->authorizeExport('limousine.receipt');

        return $this->renderer->print($this->rows->headings(), $this->rowsFor($request), __('Receipts'));
    }

    /**
     * @return list<array<string, string>>
     */
    private function rowsFor(Request $request): array
    {
        // "Live entry data" is a backup of everything the historical import
        // never touched, not a filter on top of the current tab/method/date —
        // so it ignores all of those and asks for the complete live set.
        if ($request->boolean('live')) {
            return $this->rows->all('', '', '', '', '', '', true);
        }

        return $this->rows->all(
            (string) $request->query('tab', ''),
            (string) $request->query('method', ''),
            (string) $request->query('q', ''),
            (string) $request->query('from', ''),
            (string) $request->query('to', ''),
            (string) $request->query('prepared_by', ''),
            false,
            $this->ids($request),
        );
    }

    /**
     * The ticked receipts' own documents, one page each, in ticked order.
     *
     * @param  list<int>  $ids
     */
    private function receiptDocuments(array $ids): Response
    {
        abort_if(count($ids) > self::MAX_DOCUMENTS, 422, __('Tick at most :max receipts to download them as PDF.', ['max' => self::MAX_DOCUMENTS]));

        $order = array_flip($ids);

        $receipts = LimoReceipt::query()
            ->with(['customer', 'invoice', 'booking'])
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(static fn (LimoReceipt $receipt): int => $order[$receipt->id] ?? PHP_INT_MAX)
            ->values();

        $first = $receipts->first();
        if ($first === null) {
            abort(404);
        }

        [$bytes, $filename] = $receipts->count() === 1
            ? [$this->receiptPdf->render($first), $this->receiptPdf->filename($first)]
            : [$this->receiptPdf->renderMany($receipts), $this->receiptPdf->filenameForMany($receipts->count())];

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * The ticked rows, as `?ids=3,7,12`; empty (or junk) = the whole tab.
     *
     * @return list<int>
     */
    private function ids(Request $request): array
    {
        $raw = $request->query('ids');
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_values(array_unique(array_map('intval', array_filter(explode(',', $raw), 'is_numeric'))));
    }
}
