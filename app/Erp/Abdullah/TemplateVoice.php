<?php

declare(strict_types=1);

namespace App\Erp\Abdullah;

/**
 * What Abdullah says when Claude is not available: no key, the month's limit
 * reached, or Claude failing. Plain fixed sentences, in Arabic or English
 * (Malayalam and Tagalog fall back to English), built only from the facts.
 */
final class TemplateVoice
{
    /**
     * @param  array<string, mixed>  $facts
     */
    public function write(string $language, string $kind, array $facts, string $userName): string
    {
        $locale = $language === 'ar' ? 'ar' : 'en';
        $t = static fn (string $key, array $params = []): string => (string) __($key, $params, $locale);

        return match ($kind) {
            'booking' => trim($t('Well done :name, booking :reference is saved. Today you have :count bookings. Now bring me the next one.', [
                'name' => $userName,
                'reference' => (string) ($facts['reference'] ?? ''),
                'count' => (string) ($facts['today_count'] ?? 1),
            ]) . (isset($facts['price_check']) ? ' ' . $this->summary($facts['price_check'], $t) : '')),
            'check' => trim($t(':name, please check this.', ['name' => $userName]) . ' ' . $this->summary($facts, $t)),
            'trip', 'escalation' => $t('These trips today still have no driver or car: :trips', [
                'trips' => implode(', ', array_map(
                    static fn ($trip): string => is_array($trip) ? ((string) ($trip['reference'] ?? '') . ' (' . (string) ($trip['time'] ?? '') . ')') : '',
                    is_array($facts['trips'] ?? null) ? $facts['trips'] : [],
                )),
            ]),
            default => $t('I can only help a little right now. Please try again later.'),
        };
    }

    /**
     * @param  mixed  $facts
     * @param  \Closure(string, array<string, string>): string  $t
     */
    private function summary(mixed $facts, \Closure $t): string
    {
        if (! is_array($facts) || ! is_string($facts['summary_key'] ?? null)) {
            return '';
        }

        $params = is_array($facts['summary_params'] ?? null) ? array_map('strval', $facts['summary_params']) : [];

        return $t($facts['summary_key'], $params);
    }
}
