<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Assistant;

/**
 * What a confirmed action produced: the reply to send, and optionally a PDF
 * to send with it.
 */
final class ActionOutcome
{
    public function __construct(
        public readonly string $text,
        public readonly ?string $pdf = null,
        public readonly string $filename = '',
    ) {
    }
}
