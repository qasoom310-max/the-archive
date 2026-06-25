<?php

declare(strict_types=1);

namespace App\Erp\Stream;

use RuntimeException;

/**
 * Thrown when Cloudflare Stream is unconfigured or the API returns an error.
 */
final class StreamException extends RuntimeException
{
}
