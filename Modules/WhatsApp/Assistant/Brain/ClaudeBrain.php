<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Assistant\Brain;

use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Beta\Messages\BetaToolUseBlock;
use Anthropic\Client;

/**
 * Claude, through the official Anthropic PHP SDK.
 *
 * Tuned for a chat on shared hosting: effort `low` keeps each round quick (the
 * work is routing a short request to the right tool, not deep reasoning), and a
 * tight timeout stops one slow call holding a PHP worker. Server-side refusal
 * fallbacks are on, so a policy decline is retried on the fallback model inside
 * the same call rather than surfacing as a dead end.
 */
final class ClaudeBrain implements Brain
{
    private const TIMEOUT_SECONDS = 25.0;

    private const MAX_TOKENS = 4096;

    public function respond(string $apiKey, string $model, string $system, array $messages, array $tools): BrainReply
    {
        $client = new Client(apiKey: $apiKey, requestOptions: ['timeout' => self::TIMEOUT_SECONDS, 'maxRetries' => 1]);

        $message = $client->beta->messages->create(
            maxTokens: self::MAX_TOKENS,
            messages: $messages,
            model: $model,
            system: $system,
            tools: $tools,
            outputConfig: ['effort' => 'low'],
            fallbacks: 'default',
            betas: ['server-side-fallback-2026-07-01'],
        );

        $text = '';
        $calls = [];

        foreach ($message->content as $block) {
            if ($block instanceof BetaTextBlock) {
                $text .= $block->text;
            } elseif ($block instanceof BetaToolUseBlock) {
                $calls[] = ['id' => $block->id, 'name' => $block->name, 'input' => $block->input];
            }
        }

        return new BrainReply(
            stopReason: (string) ($message->stopReason ?? ''),
            text: trim($text),
            toolCalls: $calls,
            content: array_values($message->content),
        );
    }
}
