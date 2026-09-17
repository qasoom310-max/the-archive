<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Assistant\Brain;

/**
 * The model the assistant thinks with. An interface so tests can script its
 * answers; production binds {@see ClaudeBrain}.
 */
interface Brain
{
    /**
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>> $tools
     */
    public function respond(string $apiKey, string $model, string $system, array $messages, array $tools): BrainReply;
}
