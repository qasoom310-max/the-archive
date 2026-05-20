<?php

declare(strict_types=1);

namespace App\Erp\Settings;

use Illuminate\Support\Facades\Facade;

/**
 * Static entry point to {@see SettingManager}.
 *
 * @method static bool has(string $key)
 * @method static mixed get(string $key, mixed $default = null)
 * @method static void set(string $key, mixed $value)
 * @method static void setMany(array<string, mixed> $values)
 * @method static array<string, list<\App\Models\Ir\IrConfigParameter>> grouped()
 * @method static void flush()
 *
 * @see SettingManager
 */
final class Setting extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SettingManager::class;
    }
}
