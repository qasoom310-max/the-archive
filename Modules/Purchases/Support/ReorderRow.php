<?php

declare(strict_types=1);

namespace Modules\Purchases\Support;

use Modules\Pos\Support\StockRow;

/**
 * One row of the Reorder Report: a stock-tracked item that needs buying,
 * enriched with the vendor the buying team should contact. The vendor is the
 * item's preferred supplier when set, else the last vendor it was bought from
 * (resolved by {@see \Modules\Purchases\Services\PurchaseReorderData}); null
 * when neither is known.
 */
final class ReorderRow
{
    public function __construct(
        public readonly StockRow $item,
        public readonly ?string $vendorName,
        public readonly ?string $vendorPhone,
    ) {
    }
}
