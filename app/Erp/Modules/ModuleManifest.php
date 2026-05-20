<?php

declare(strict_types=1);

namespace App\Erp\Modules;

use JsonException;
use RuntimeException;

/**
 * Parsed `module.json` manifest. Equivalent to Odoo's `__manifest__.py`.
 */
final readonly class ModuleManifest
{
    /**
     * @param list<string>       $depends
     * @param list<class-string> $providers
     * @param list<class-string> $models
     */
    public function __construct(
        public string $name,
        public string $displayName,
        public string $version,
        public string $path,
        public ?string $summary = null,
        public ?string $description = null,
        public ?string $author = null,
        public ?string $category = null,
        public ?string $icon = null,
        public array $depends = [],
        public array $providers = [],
        public array $models = [],
        public bool $application = false,
        public bool $autoInstall = false,
        public int $sequence = 100,
    ) {}

    /**
     * Build a manifest from a `module.json` file living in $modulePath.
     */
    public static function fromFile(string $manifestPath, string $modulePath): self
    {
        $raw = @file_get_contents($manifestPath);

        if ($raw === false) {
            throw new RuntimeException("Unable to read module manifest: {$manifestPath}");
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Invalid JSON in {$manifestPath}: {$e->getMessage()}", 0, $e);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException("Manifest {$manifestPath} must decode to an object.");
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;

        $name = self::string($data, 'name');

        if ($name === null) {
            throw new RuntimeException("Manifest {$manifestPath} is missing required \"name\".");
        }

        return new self(
            name: $name,
            displayName: self::string($data, 'display_name') ?? ucfirst($name),
            version: self::string($data, 'version') ?? '1.0.0',
            path: $modulePath,
            summary: self::string($data, 'summary'),
            description: self::string($data, 'description'),
            author: self::string($data, 'author'),
            category: self::string($data, 'category'),
            icon: self::string($data, 'icon'),
            depends: self::stringList($data, 'depends'),
            providers: self::stringList($data, 'providers'),
            models: self::stringList($data, 'models'),
            application: self::bool($data, 'application'),
            autoInstall: self::bool($data, 'auto_install'),
            sequence: self::int($data, 'sequence', 100),
        );
    }

    /**
     * Attributes for upserting into the `ir_module` table.
     *
     * @return array<string, mixed>
     */
    public function toModuleAttributes(): array
    {
        return [
            'display_name' => $this->displayName,
            'summary' => $this->summary,
            'description' => $this->description,
            'version' => $this->version,
            'author' => $this->author,
            'category' => $this->category,
            'icon' => $this->icon,
            'depends' => $this->depends,
            'application' => $this->application,
            'auto_install' => $this->autoInstall,
            'sequence' => $this->sequence,
        ];
    }

    public function migrationsPath(): string
    {
        return $this->path . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $data */
    private static function bool(array $data, string $key): bool
    {
        return ($data[$key] ?? false) === true;
    }

    /** @param array<string, mixed> $data */
    private static function int(array $data, string $key, int $default): int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : $default;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private static function stringList(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $item): bool => is_string($item) && $item !== '',
        ));
    }
}
