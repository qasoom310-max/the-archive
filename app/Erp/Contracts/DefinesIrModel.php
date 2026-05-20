<?php

declare(strict_types=1);

namespace App\Erp\Contracts;

use App\Erp\Registry\ModelDefinition;

/**
 * Implemented by any Eloquent model that participates in the ERP registry.
 *
 * During `module:install` the ModuleManager reflects over the module's
 * declared model classes and persists their definition into
 * `ir_model` / `ir_model_fields` / `ir_ui_view`.
 */
interface DefinesIrModel
{
    public static function irModelDefinition(): ModelDefinition;
}
