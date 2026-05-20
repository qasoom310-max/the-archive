<?php

declare(strict_types=1);

namespace App\Erp\Modules;

use RuntimeException;

final class ModuleException extends RuntimeException
{
    public static function notFound(string $name): self
    {
        return new self("Module \"{$name}\" was not found on disk.");
    }

    public static function missingDependency(string $module, string $dependency): self
    {
        return new self("Module \"{$module}\" depends on \"{$dependency}\", which is not available.");
    }

    public static function dependents(string $name, string $dependent): self
    {
        return new self("Cannot uninstall \"{$name}\": module \"{$dependent}\" still depends on it.");
    }

    public static function circular(string $name): self
    {
        return new self("Circular dependency detected while resolving module \"{$name}\".");
    }
}
