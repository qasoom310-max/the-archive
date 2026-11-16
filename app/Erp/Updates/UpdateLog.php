<?php

declare(strict_types=1);

namespace App\Erp\Updates;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use JsonException;

/**
 * The system's own news: every change shipped, written for the people who use
 * it, newest first.
 *
 * Entries are files in `resources/updates/`, one per change, named
 * `YYYY-MM-DD-short-name.json`. They live in the repository on purpose:
 *
 *  - whoever makes a change writes its entry in the same commit, so the news
 *    cannot lag behind the code or be forgotten on the server;
 *  - a deploy carries them to every workspace at once (a database table would
 *    have to be filled in each tenant database separately);
 *  - one file per change means two people working at once never collide.
 *
 * See CLAUDE.md §2 rule 10 for what an entry must say.
 */
final class UpdateLog
{
    /** The apps an entry can belong to, in the order the filter shows them. */
    public const APPS = [
        'system', 'limousine', 'rental', 'pos', 'accounting', 'purchases',
        'inventory', 'contacts', 'project', 'whatsapp', 'website',
    ];

    /** Languages every entry must be written in. */
    public const LOCALES = ['en', 'ar'];

    /**
     * From this day on an entry must carry the full story — the explanation
     * and the step-by-step guide — not only the summary. Earlier entries were
     * written before that was asked for and are left as summaries.
     */
    public const FULL_STORY_FROM = '2026-11-16';

    /** Picture file names: lowercase, no folders, images only. */
    private const IMAGE_NAME = '/^[a-z0-9][a-z0-9-]*\.(png|jpg|jpeg|webp)$/';

    private string $directory;

    /** @var list<UpdateEntry>|null */
    private ?array $entries = null;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? resource_path('updates');
    }

    /**
     * Every entry, newest first.
     *
     * @return list<UpdateEntry>
     */
    public function all(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $files = glob($this->directory . '/*.json');
        $entries = [];
        foreach ($files === false ? [] : $files as $file) {
            // The megaphone in the top bar reads this on EVERY page. A bad
            // entry must cost one news item, never the whole system: it is
            // reported and left out. The test suite calls read() on each file
            // directly, so a bad one still stops at CI.
            try {
                $entries[] = $this->read($file);
            } catch (InvalidArgumentException $e) {
                report($e);
            }
        }

        // Newest day first; within a day, by file name so the order is stable.
        usort($entries, static function (UpdateEntry $a, UpdateEntry $b): int {
            return [$b->date->getTimestamp(), $b->key] <=> [$a->date->getTimestamp(), $a->key];
        });

        return $this->entries = $entries;
    }

    /** One entry by its key (its file name without `.json`), or null. */
    public function find(string $key): ?UpdateEntry
    {
        foreach ($this->all() as $entry) {
            if ($entry->key === $key) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Where an entry's picture is on disk, or null when the entry does not
     * list it. Only files an entry names can ever be served.
     */
    public function imagePath(string $key, string $file): ?string
    {
        $entry = $this->find($key);
        if ($entry === null || ! $entry->hasImage($file)) {
            return null;
        }

        $path = $this->directory . '/images/' . $key . '/' . $file;

        return is_file($path) ? $path : null;
    }

    /** The newest entry's key, for the "something new" dot. Null when there are none. */
    public function latestKey(): ?string
    {
        return ($this->all()[0] ?? null)?->key;
    }

    /**
     * Read and check one file. Throws on anything that would make the page
     * show a broken or half-translated entry — the test suite reads every file
     * through here, so a bad entry stops at CI and never reaches the site.
     */
    public function read(string $file): UpdateEntry
    {
        $key = basename($file, '.json');

        if (preg_match('/^(\d{4}-\d{2}-\d{2})-[a-z0-9]+(?:-[a-z0-9]+)*$/', $key, $m) !== 1) {
            throw new InvalidArgumentException("Update file [{$key}] must be named YYYY-MM-DD-short-name.json.");
        }

        try {
            $data = json_decode((string) file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException("Update file [{$key}] is not valid JSON: {$e->getMessage()}");
        }

        if (! is_array($data)) {
            throw new InvalidArgumentException("Update file [{$key}] must hold a JSON object.");
        }

        $date = Carbon::createFromFormat('!Y-m-d', $m[1]);
        if ($date === null || $date->format('Y-m-d') !== $m[1]) {
            throw new InvalidArgumentException("Update file [{$key}] does not start with a real date.");
        }

        $app = $data['app'] ?? null;
        if (! is_string($app) || ! in_array($app, self::APPS, true)) {
            throw new InvalidArgumentException("Update file [{$key}] needs \"app\" to be one of: " . implode(', ', self::APPS) . '.');
        }

        $title = [];
        $points = [];
        foreach (self::LOCALES as $locale) {
            $t = $data['title'][$locale] ?? null;
            if (! is_string($t) || trim($t) === '') {
                throw new InvalidArgumentException("Update file [{$key}] needs a title in [{$locale}].");
            }
            $title[$locale] = trim($t);

            $lines = $data['points'][$locale] ?? null;
            if (! is_array($lines) || $lines === []) {
                throw new InvalidArgumentException("Update file [{$key}] needs at least one point in [{$locale}].");
            }

            $points[$locale] = [];
            foreach ($lines as $line) {
                if (! is_string($line) || trim($line) === '') {
                    throw new InvalidArgumentException("Update file [{$key}] has an empty point in [{$locale}].");
                }
                $points[$locale][] = trim($line);
            }
        }

        if (count($points['en']) !== count($points['ar'])) {
            throw new InvalidArgumentException("Update file [{$key}] must have the same number of points in each language.");
        }

        $details = $this->readLines($key, $data, 'details');
        $guide = $this->readLines($key, $data, 'guide');

        if ($m[1] >= self::FULL_STORY_FROM && ($details === [] || $guide === [])) {
            throw new InvalidArgumentException("Update file [{$key}] needs \"details\" (the full explanation) and \"guide\" (the steps), in every language.");
        }

        return new UpdateEntry($key, $date, $app, $title, $points, $details, $guide, $this->readImages($key, $data));
    }

    /**
     * An optional per-language list of lines ("details" paragraphs, "guide"
     * steps). Absent is fine; present must be complete and even.
     *
     * @param array<mixed> $data
     *
     * @return array<string, list<string>>
     */
    private function readLines(string $key, array $data, string $field): array
    {
        if (! array_key_exists($field, $data)) {
            return [];
        }

        $out = [];
        foreach (self::LOCALES as $locale) {
            $lines = $data[$field][$locale] ?? null;
            if (! is_array($lines) || $lines === []) {
                throw new InvalidArgumentException("Update file [{$key}] needs \"{$field}\" in [{$locale}].");
            }

            $out[$locale] = [];
            foreach ($lines as $line) {
                if (! is_string($line) || trim($line) === '') {
                    throw new InvalidArgumentException("Update file [{$key}] has an empty line in \"{$field}\" [{$locale}].");
                }
                $out[$locale][] = trim($line);
            }
        }

        if (count($out['en']) !== count($out['ar'])) {
            throw new InvalidArgumentException("Update file [{$key}] must have the same number of \"{$field}\" lines in each language.");
        }

        return $out;
    }

    /**
     * The optional before/after pictures. Each needs at least one picture, a
     * caption in every language, and its files present in
     * `images/<entry key>/` beside the entries.
     *
     * @param array<mixed> $data
     *
     * @return list<array{before: ?string, after: ?string, caption: array<string, string>}>
     */
    private function readImages(string $key, array $data): array
    {
        $images = $data['images'] ?? [];
        if (! is_array($images)) {
            throw new InvalidArgumentException("Update file [{$key}]: \"images\" must be a list.");
        }

        $out = [];
        foreach ($images as $image) {
            if (! is_array($image)) {
                throw new InvalidArgumentException("Update file [{$key}]: each image must be an object.");
            }

            $pair = ['before' => null, 'after' => null];
            foreach (array_keys($pair) as $side) {
                $file = $image[$side] ?? null;
                if ($file === null) {
                    continue;
                }
                if (! is_string($file) || preg_match(self::IMAGE_NAME, $file) !== 1) {
                    throw new InvalidArgumentException("Update file [{$key}]: \"{$side}\" must be a plain image file name (lowercase letters, digits, dashes; png, jpg or webp).");
                }
                if (! is_file($this->directory . '/images/' . $key . '/' . $file)) {
                    throw new InvalidArgumentException("Update file [{$key}]: picture [{$file}] is not in images/{$key}/.");
                }
                $pair[$side] = $file;
            }

            if ($pair['before'] === null && $pair['after'] === null) {
                throw new InvalidArgumentException("Update file [{$key}]: an image needs a \"before\" or an \"after\" picture.");
            }

            $caption = [];
            foreach (self::LOCALES as $locale) {
                $text = $image['caption'][$locale] ?? null;
                if (! is_string($text) || trim($text) === '') {
                    throw new InvalidArgumentException("Update file [{$key}]: every image needs a caption in [{$locale}].");
                }
                $caption[$locale] = trim($text);
            }

            $out[] = ['before' => $pair['before'], 'after' => $pair['after'], 'caption' => $caption];
        }

        return $out;
    }
}
