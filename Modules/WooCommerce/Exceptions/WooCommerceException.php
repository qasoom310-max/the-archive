<?php

declare(strict_types=1);

namespace Modules\WooCommerce\Exceptions;

use RuntimeException;

/**
 * Thrown when the WooCommerce store rejects a request (non-2xx) so the
 * queued job retries, then lands in `failed_jobs`.
 */
final class WooCommerceException extends RuntimeException
{
}
