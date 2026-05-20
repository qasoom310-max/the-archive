<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Demo\DemoTicket;
use App\Models\Ir\IrModel;
use App\Models\Ir\IrUiView;
use Illuminate\Database\Seeder;

/**
 * Registers the demo.ticket model + explicit List/Kanban views so the
 * Phase 4 view engine has stored `arch` metadata to render (the same
 * mechanism the Contacts module uses for real in Phase 5).
 */
final class DemoViewSeeder extends Seeder
{
    public function run(): void
    {
        $model = IrModel::query()->updateOrCreate(
            ['model' => 'demo.ticket'],
            [
                'name' => 'Demo Ticket',
                'class' => DemoTicket::class,
                'table' => 'demo_tickets',
                'is_custom' => false,
            ],
        );

        $fields = [
            ['name' => 'subject', 'label' => 'Subject', 'ttype' => 'char', 'sequence' => 10],
            ['name' => 'stage', 'label' => 'Stage', 'ttype' => 'selection', 'sequence' => 20],
            ['name' => 'amount', 'label' => 'Amount', 'ttype' => 'float', 'sequence' => 30],
            ['name' => 'updated_at', 'label' => 'Updated', 'ttype' => 'datetime', 'sequence' => 40],
        ];

        foreach ($fields as $field) {
            $model->fields()->updateOrCreate(['name' => $field['name']], $field);
        }

        IrUiView::query()->updateOrCreate(
            ['model' => 'demo.ticket', 'type' => 'list'],
            [
                'name' => 'Demo Tickets (List)',
                'arch' => [
                    'columns' => [
                        ['field' => 'subject', 'label' => 'Subject', 'sortable' => true],
                        ['field' => 'stage', 'label' => 'Stage', 'format' => 'badge', 'sortable' => true],
                        ['field' => 'amount', 'label' => 'Amount', 'format' => 'number', 'align' => 'right', 'sum' => true, 'sortable' => true],
                        ['field' => 'updated_at', 'label' => 'Updated', 'format' => 'datetime', 'sortable' => true],
                    ],
                    'default_sort' => [['field' => 'subject', 'dir' => 'asc']],
                    'per_page' => 8,
                ],
            ],
        );

        IrUiView::query()->updateOrCreate(
            ['model' => 'demo.ticket', 'type' => 'kanban'],
            [
                'name' => 'Demo Tickets (Kanban)',
                'arch' => [
                    'group_by' => 'stage',
                    'stages' => [
                        ['value' => 'New', 'label' => 'New'],
                        ['value' => 'In Progress', 'label' => 'In Progress'],
                        ['value' => 'Blocked', 'label' => 'Blocked'],
                        ['value' => 'Done', 'label' => 'Done'],
                    ],
                    'card' => [
                        'title' => 'subject',
                        'subtitle' => 'amount',
                        'badges' => ['stage'],
                    ],
                    'rotting' => ['field' => 'updated_at', 'days' => 7],
                ],
            ],
        );
    }
}
