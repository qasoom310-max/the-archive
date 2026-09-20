<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Assistant\Brain;

/**
 * One model response, reduced to what the assistant loop needs.
 *
 * `content` is the response's content exactly as the provider returned it, so
 * it can be appended to the conversation unchanged on the next call inside the
 * same turn (thinking and tool-use blocks must be passed back as they came).
 */
final class BrainReply
{
    /**
     * @param list<array{id: string, name: string, input: array<string, mixed>}> $toolCalls
     * @param list<mixed> $content
     */
    public function __construct(
        public readonly string $stopReason,
        public readonly string $text,
        public readonly array $toolCalls,
        public readonly array $content,
    ) {
    }

    public function wantsTools(): bool
    {
        return $this->stopReason === 'tool_use' && $this->toolCalls !== [];
    }
}
