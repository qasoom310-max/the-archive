<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Exceptions;

use RuntimeException;

/**
 * Raised when WhatsApp messaging cannot proceed: missing/disabled
 * configuration, or a non-2xx response from the Meta Graph API.
 */
final class WhatsAppException extends RuntimeException
{
}
