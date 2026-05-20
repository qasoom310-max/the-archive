<?php

declare(strict_types=1);

namespace Tests\Fixtures\Demo;

use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use Illuminate\Database\Eloquent\Model;

/**
 * Fixture model used by ModuleEngineTest to verify the ir_* registry sync.
 */
final class DemoWidget extends Model implements DefinesIrModel
{
    protected $table = 'demo_widgets';

    protected $guarded = [];

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'demo.widget',
            name: 'Demo Widget',
            class: self::class,
            table: 'demo_widgets',
            fields: [
                new FieldDefinition('name', 'Name', 'char', required: true),
                new FieldDefinition('qty', 'Quantity', 'integer'),
            ],
            views: [
                new ViewDefinition('Demo Widgets', 'list', ['columns' => ['name', 'qty']]),
            ],
        );
    }
}
