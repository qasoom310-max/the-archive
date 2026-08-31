<?php

declare(strict_types=1);

namespace App\Erp\Contracts;

/**
 * Something a customer's credit can be spent against.
 *
 * Credit is issued by one business (a limousine trip called off too late to
 * refund) and the customer may well want to spend it on another — a rental car
 * from the same group. So what a coupon can pay for is defined HERE, in the app
 * layer, rather than in either module: a limousine booking and a rental order
 * both implement it, and neither has to know the other exists.
 *
 * Three questions, which is all redeeming needs to ask: what is still owed,
 * how do I put money against it, and what do I call it in the record.
 */
interface TakesCouponCredit
{
    /** What is still owed on this, in BHD. Zero means nothing to pay. */
    public function couponBalanceDue(): float;

    /**
     * Put credit against it.
     *
     * Adds to money already received rather than replacing it, and leaves the
     * thing SAVED with its payment state settled — redeeming is finished when
     * this returns, not half-done pending a caller remembering to save.
     */
    public function applyCouponCredit(float $amount): void;

    /** How this appears on the coupon's history: "BK/00042", "RO/00019". */
    public function couponReference(): string;
}
