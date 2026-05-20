<?php

declare(strict_types=1);

namespace App\Erp\Settings;

use App\Models\Ir\IrConfigParameter;
use Illuminate\Support\Facades\Cache;

/**
 * Central, cached accessor for `ir_config_parameter`. The whole parameter
 * set is loaded once per request cycle and kept in Laravel's cache, so
 * `get()` never hits the database on a warm cache. Writes flush it.
 */
final class SettingManager
{
    private const CACHE_KEY = 'erp.settings.all';

    /**
     * Cached key → {value,type} map.
     *
     * @return array<string, array{value: string|null, type: string}>
     */
    private function map(): array
    {
        /** @var array<string, array{value: string|null, type: string}> $map */
        $map = Cache::rememberForever(self::CACHE_KEY, static function (): array {
            $out = [];

            foreach (IrConfigParameter::query()->get(['key', 'value', 'type']) as $param) {
                $out[$param->key] = ['value' => $param->value, 'type' => $param->type];
            }

            return $out;
        });

        return $map;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->map());
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $row = $this->map()[$key] ?? null;

        if ($row === null) {
            return $default;
        }

        return $this->cast($row['value'], $row['type']);
    }

    public function set(string $key, mixed $value): void
    {
        $this->persist($key, $value);
        $this->flush();
    }

    /**
     * Bulk update — one cache flush for the whole batch.
     *
     * @param array<string, mixed> $values  key => raw form value
     */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->persist($key, $value);
        }

        $this->flush();
    }

    /**
     * Every parameter bucketed by `group`, ordered by `sort`, for the
     * settings UI tabs.
     *
     * @return array<string, list<IrConfigParameter>>
     */
    public function grouped(): array
    {
        $out = [];

        $params = IrConfigParameter::query()
            ->orderBy('group')
            ->orderBy('sort')
            ->orderBy('label')
            ->get();

        foreach ($params as $param) {
            $out[$param->group][] = $param;
        }

        return $out;
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function persist(string $key, mixed $value): void
    {
        $param = IrConfigParameter::query()->firstOrNew(['key' => $key]);

        // Existing rows keep their metadata; only the value/type change.
        $type = $param->exists ? $param->type : $this->inferType($value);

        $param->value = $this->serialize($value, $type);
        $param->type = $type;

        if (! $param->exists) {
            $param->group = 'General';
            $param->label = $key;
        }

        $param->save();
    }

    private function cast(?string $value, string $type): mixed
    {
        if ($value === null) {
            return $type === 'bool' ? false : null;
        }

        return match ($type) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'number' => str_contains($value, '.') ? (float) $value : (int) $value,
            default => $value,
        };
    }

    private function serialize(mixed $value, string $type): ?string
    {
        return match ($type) {
            'bool' => $value ? '1' : '0',
            'number' => is_numeric($value) ? (string) (0 + $value) : '0',
            default => $value === null ? null : (string) $value,
        };
    }

    private function inferType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'bool',
            is_int($value), is_float($value) => 'number',
            default => 'string',
        };
    }
}
