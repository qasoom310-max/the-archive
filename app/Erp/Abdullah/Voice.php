<?php

declare(strict_types=1);

namespace App\Erp\Abdullah;

/**
 * Turns an event and its facts into what Abdullah says. Claude in production;
 * tests bind a fake with app()->instance(Voice::class, ...).
 */
interface Voice
{
    /**
     * @param  array<string, mixed>  $facts  what the ERP found; the only numbers he may use
     * @param  list<array{who: string, text: string}>  $history  the conversation so far (chat only)
     */
    public function write(string $apiKey, string $model, string $language, string $kind, array $facts, string $userName, array $history = [], ?string $userText = null): string;
}
