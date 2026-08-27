<?php

declare(strict_types=1);

namespace Tests\Feature;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Every user-facing string must have an Arabic translation.
 *
 * A `__()` call with no entry in `lang/ar.json` renders the ENGLISH source —
 * silently, with no error — so an Arabic screen regresses one phrase at a time
 * and nobody notices until a customer does. This test is the alarm.
 *
 * Adding a string? Add its `lang/ar.json` entry in the same change. If it is
 * genuinely meant to stay English, add it to {@see ENGLISH_BY_DESIGN} below
 * with the reason, so the exemption is a decision on the record rather than an
 * oversight.
 */
final class ArabicCoverageTest extends TestCase
{
    /** Directories scanned for `__()` calls. */
    private const ROOTS = ['app', 'Modules', 'resources', 'routes', 'database'];

    /**
     * Strings deliberately left in English (CLAUDE.md §2: brand names and
     * identifiers stay English).
     *
     * @var list<string>
     */
    private const ENGLISH_BY_DESIGN = [
        // The WooCommerce and Cloudflare Stream settings tabs are integration
        // surfaces, kept in the vendor's own language like the WhatsApp tab.
        ':count products synced to the store.',
        ':count products synced. :remaining still to go — press Sync again to continue.',
        ':synced synced, :failed failed. First error: :error',
        'A store URL, consumer key and secret are required before enabling sync.',
        'Configure and enable the store, then Save first.',
        'No active products in THIS database to sync — switch to the right database (My database) or add products here first.',
        'An Account ID and API token are required before enabling.',

        // Identifiers, units and punctuation.
        'PDF',
        // File-format names on the queue's export bar, alongside PDF. These are
        // the formats' own names, recognised as-is in either language.
        'CSV',
        'Excel',
        'ml',
        '—',
        '…',
    ];

    public function test_every_user_facing_string_has_an_arabic_translation(): void
    {
        $ar = json_decode((string) file_get_contents(base_path('lang/ar.json')), true);
        $this->assertIsArray($ar, 'lang/ar.json must be valid JSON');

        $missing = [];

        foreach ($this->translatableStrings() as $key => $file) {
            if ($key === '' || in_array($key, self::ENGLISH_BY_DESIGN, true)) {
                continue;
            }

            if (! array_key_exists($key, $ar)) {
                $missing[] = $key . '   [' . basename($file) . ']';
            }
        }

        sort($missing);

        $this->assertSame([], $missing, sprintf(
            "%d string(s) would render in English on an Arabic screen. Add them to lang/ar.json:\n  - %s",
            count($missing),
            implode("\n  - ", $missing),
        ));
    }

    /**
     * Every `__('literal')` in the codebase, mapped to the file it came from.
     * Only literals are collected — a `__($variable)` cannot be checked here.
     *
     * @return array<string, string>
     */
    private function translatableStrings(): array
    {
        $found = [];

        $single = "~__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'~";
        $double = '~__\(\s*"((?:[^"\\\\$]|\\\\.)*)"~';

        foreach (self::ROOTS as $root) {
            $dir = base_path($root);

            if (! is_dir($dir)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                foreach ([$single, $double] as $pattern) {
                    if (preg_match_all($pattern, $source, $matches)) {
                        foreach ($matches[1] as $literal) {
                            $found[stripcslashes($literal)] ??= $file->getPathname();
                        }
                    }
                }
            }
        }

        return $found;
    }
}
