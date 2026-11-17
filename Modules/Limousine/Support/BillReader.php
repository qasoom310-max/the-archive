<?php

declare(strict_types=1);

namespace Modules\Limousine\Support;

/**
 * Reads a photo of a bill and reports what is printed on it.
 *
 * An interface so the scan can be tested without calling out: the real reader
 * sends the photo to Claude, a test hands back a fixed answer.
 */
interface BillReader
{
    /**
     * @param string $image raw image bytes (JPEG, PNG or WebP)
     *
     * @return array{
     *     readable: bool,
     *     date: string,
     *     payee: string,
     *     category: string,
     *     net_amount: float,
     *     vat_amount: float,
     *     total: float,
     *     currency: string,
     *     description: string,
     * } figures exactly as printed, in the bill's own currency; 0 or '' where the bill does not show one. `currency` is an ISO code, or '' when the bill does not say
     */
    public function read(string $apiKey, string $model, string $image, string $mime): array;
}
