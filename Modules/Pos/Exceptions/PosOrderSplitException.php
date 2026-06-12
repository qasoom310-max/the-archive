<?php

declare(strict_types=1);

namespace Modules\Pos\Exceptions;

use RuntimeException;

/**
 * A split request could not be honoured — bad state, an empty / invalid
 * selection, or one that would empty the source order. Carries a
 * user-facing message the split modal renders inline; thrown by
 * {@see \Modules\Pos\Services\PosOrderSplitter} before any row is touched
 * (it runs inside a DB transaction, so a throw rolls the whole split back).
 */
final class PosOrderSplitException extends RuntimeException
{
}
