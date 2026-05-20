<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

/**
 * Odoo-faithful location types. Only `Internal` locations hold company
 * stock that is valued; the others are the "double-entry" counterparts
 * a move debits/credits against.
 */
enum LocationType: string
{
    case Vendor = 'vendor';        // goods owned by a supplier (pre-receipt)
    case View = 'view';            // non-stockable grouping node (hierarchy)
    case Internal = 'internal';    // company-owned, valued stock
    case Customer = 'customer';    // goods delivered to a customer
    case Inventory = 'inventory';  // counterpart for adjustments / loss
    case Production = 'production'; // counterpart for manufacturing
    case Transit = 'transit';      // inter-warehouse / in-transit

    public function label(): string
    {
        return match ($this) {
            self::Vendor => 'Vendor',
            self::View => 'View',
            self::Internal => 'Internal',
            self::Customer => 'Customer',
            self::Inventory => 'Inventory Loss',
            self::Production => 'Production',
            self::Transit => 'Transit',
        };
    }

    public function isInternal(): bool
    {
        return $this === self::Internal;
    }
}
