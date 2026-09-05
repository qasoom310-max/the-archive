<?php

declare(strict_types=1);

namespace Modules\Rental\Models\Concerns;

/**
 * Every table that may hold a customer's id. One list for both classes that
 * read the shared `rental_customers` table, so the rule is the same whichever
 * app the person is deleted from.
 *
 * @see \App\Models\Concerns\GuardsDeletionWhenReferenced
 */
trait CustomerDeletionReferences
{
    /**
     * @return array<string, array{0: list<string>, 1: string}>
     */
    protected static function deletionReferences(): array
    {
        return [
            'rental_orders' => [['customer_id'], __('rental orders')],
            'rental_quotations' => [['customer_id'], __('rental quotations')],
            'rental_invoices' => [['customer_id'], __('rental invoices')],
            'rental_receipts' => [['customer_id'], __('rental receipts')],
            'rental_replacements' => [['customer_id'], __('replacements')],
            'limo_bookings' => [['customer_id'], __('limousine bookings')],
            'limo_quotations' => [['customer_id'], __('limousine quotations')],
            'limo_invoices' => [['customer_id'], __('limousine invoices')],
            'limo_receipts' => [['customer_id'], __('limousine receipts')],
            'limo_coupons' => [['customer_id'], __('coupons')],
        ];
    }
}
