<?php

declare(strict_types=1);

namespace App\Erp\Updates;

use Illuminate\Support\Carbon;

/**
 * One item on the "What's new" page: a change to the system, told to the
 * people who use it. Read from a file under `resources/updates/`.
 */
final class UpdateEntry
{
    /**
     * @param array<string, string>       $title   locale => headline
     * @param array<string, list<string>> $points  locale => what changed, one line each (the summary)
     * @param array<string, list<string>> $details locale => the full explanation, one paragraph each
     * @param array<string, list<string>> $guide   locale => how to use it, one step each
     * @param list<array{before: ?string, after: ?string, caption: array<string, string>}> $images
     *                                             before/after pictures (file names inside the entry's image folder)
     */
    public function __construct(
        public readonly string $key,
        public readonly Carbon $date,
        public readonly string $app,
        public readonly array $title,
        public readonly array $points,
        public readonly array $details = [],
        public readonly array $guide = [],
        public readonly array $images = [],
    ) {
    }

    /**
     * The full explanation in the given language, falling back to English.
     *
     * @return list<string>
     */
    public function detailsIn(string $locale): array
    {
        return $this->details[$locale] ?? $this->details['en'] ?? [];
    }

    /**
     * The step-by-step guide in the given language, falling back to English.
     *
     * @return list<string>
     */
    public function guideIn(string $locale): array
    {
        return $this->guide[$locale] ?? $this->guide['en'] ?? [];
    }

    /** Whether there is more to read than the summary on the list. */
    public function hasFullStory(): bool
    {
        return $this->details !== [] || $this->guide !== [] || $this->images !== [];
    }

    /** Whether the named picture belongs to this entry. */
    public function hasImage(string $file): bool
    {
        foreach ($this->images as $image) {
            if ($image['before'] === $file || $image['after'] === $file) {
                return true;
            }
        }

        return false;
    }

    /** The headline in the given language, falling back to English. */
    public function titleIn(string $locale): string
    {
        return $this->title[$locale] ?? $this->title['en'] ?? '';
    }

    /**
     * The lines in the given language, falling back to English.
     *
     * @return list<string>
     */
    public function pointsIn(string $locale): array
    {
        return $this->points[$locale] ?? $this->points['en'] ?? [];
    }

    /** Whether a search term appears anywhere in the entry, in either language. */
    public function matches(string $term): bool
    {
        $needle = mb_strtolower(trim($term));
        if ($needle === '') {
            return true;
        }

        $haystack = mb_strtolower(implode("\n", [
            ...array_values($this->title),
            ...array_merge(...array_values($this->points)),
            ...array_merge([], ...array_values($this->details)),
            ...array_merge([], ...array_values($this->guide)),
        ]));

        return str_contains($haystack, $needle);
    }
}
