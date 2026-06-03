<?php

declare(strict_types=1);

namespace Modules\Project\Models;

use App\Erp\Chatter\Chatterable;
use App\Erp\Chatter\HasChatter;
use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use App\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Project\Enums\KanbanState;

/**
 * A task on a project board. Self-nests via `parent_id` for sub-tasks, can
 * be assigned to a user, carries a priority star and a normal/blocked/done
 * kanban state, and budgets `planned_hours` against which timesheets burn.
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $stage_id
 * @property int|null $parent_id
 * @property string $title
 * @property string|null $description
 * @property int|null $user_id
 * @property bool $priority
 * @property KanbanState $kanban_state
 * @property string|null $blocked_reason
 * @property float $planned_hours
 * @property int $sequence
 * @property-read float $effective_hours  Computed: Σ timesheet hours.
 * @property-read float $remaining_hours  Computed: planned − effective.
 */
final class ProjectTask extends Model implements Chatterable, DefinesIrModel
{
    use HasChatter;

    protected $table = 'project_tasks';

    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'stage_id',
        'parent_id',
        'title',
        'description',
        'user_id',
        'priority',
        'kanban_state',
        'blocked_reason',
        'planned_hours',
        'sequence',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'kanban_state' => 'normal',
        'priority' => false,
        'planned_hours' => 0,
        'sequence' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'stage_id' => 'integer',
            'parent_id' => 'integer',
            'user_id' => 'integer',
            'priority' => 'boolean',
            'kanban_state' => KanbanState::class,
            'planned_hours' => 'float',
            'sequence' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * @return BelongsTo<ProjectStage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(ProjectStage::class, 'stage_id');
    }

    /**
     * The assigned employee.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Parent task (set on a sub-task).
     *
     * @return BelongsTo<ProjectTask, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Sub-tasks of this task, in board order.
     *
     * @return HasMany<ProjectTask, $this>
     */
    public function subtasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sequence');
    }

    /**
     * @return HasMany<ProjectTimesheet, $this>
     */
    public function timesheets(): HasMany
    {
        return $this->hasMany(ProjectTimesheet::class, 'task_id');
    }

    /**
     * Σ of logged timesheet hours. Prefers a `withSum()` aggregate when the
     * board query eager-loaded it (so a wall of cards doesn't N+1); falls
     * back to a single SUM query for a standalone model.
     *
     * @return Attribute<float, never>
     */
    protected function effectiveHours(): Attribute
    {
        return Attribute::make(
            get: function (): float {
                $preloaded = $this->attributes['timesheets_sum_unit_amount'] ?? null;

                if ($preloaded !== null) {
                    return round((float) $preloaded, 2);
                }

                if (! $this->exists) {
                    return 0.0;
                }

                return round((float) $this->timesheets()->sum('unit_amount'), 2);
            },
        );
    }

    /**
     * planned − effective. Negative ⇒ the task is over budget.
     *
     * @return Attribute<float, never>
     */
    protected function remainingHours(): Attribute
    {
        return Attribute::make(
            get: fn (): float => round($this->planned_hours - $this->effective_hours, 2),
        );
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'project.task',
            name: 'Task',
            class: self::class,
            table: 'project_tasks',
            module: 'project',
            fields: [
                new FieldDefinition('title', 'Title', 'char', required: true, sequence: 10),
                new FieldDefinition('project_id', 'Project', 'many2one', relation: 'project.project', required: true, sequence: 20),
                new FieldDefinition('stage_id', 'Stage', 'many2one', relation: 'project.stage', sequence: 30),
                new FieldDefinition('parent_id', 'Parent task', 'many2one', relation: 'project.task', sequence: 40),
                new FieldDefinition('user_id', 'Assignee', 'many2one', relation: 'res.users', sequence: 50),
                new FieldDefinition('kanban_state', 'State', 'selection', selection: [
                    ['value' => 'normal', 'label' => 'In Progress'],
                    ['value' => 'blocked', 'label' => 'Blocked'],
                    ['value' => 'done', 'label' => 'Ready'],
                ], sequence: 60),
                new FieldDefinition('priority', 'Priority', 'boolean', sequence: 70),
                new FieldDefinition('planned_hours', 'Planned hours', 'float', sequence: 80),
            ],
            views: [
                new ViewDefinition('Tasks', 'list', [
                    'columns' => [
                        ['field' => 'title', 'label' => 'Title', 'sortable' => true],
                        ['field' => 'kanban_state', 'label' => 'State', 'format' => 'badge', 'sortable' => true],
                        ['field' => 'priority', 'label' => 'Priority', 'format' => 'toggle'],
                        ['field' => 'planned_hours', 'label' => 'Planned', 'format' => 'number', 'align' => 'right', 'sortable' => true],
                    ],
                    'default_sort' => [['field' => 'sequence', 'dir' => 'asc']],
                    'per_page' => 30,
                    'searchable' => ['title'],
                ]),
                new ViewDefinition('Task', 'form', [
                    'cols' => 2,
                    'fields' => [
                        ['field' => 'title', 'label' => 'Title', 'widget' => 'text', 'required' => true],
                        [
                            'field' => 'project_id',
                            'label' => 'Project',
                            'widget' => 'select',
                            'required' => true,
                            'optionsFrom' => ['model' => Project::class, 'value' => 'id', 'label' => 'name', 'orderBy' => 'name'],
                        ],
                        [
                            'field' => 'stage_id',
                            'label' => 'Stage',
                            'widget' => 'select',
                            'optionsFrom' => ['model' => ProjectStage::class, 'value' => 'id', 'label' => 'name', 'orderBy' => 'sequence'],
                        ],
                        [
                            'field' => 'parent_id',
                            'label' => 'Parent task',
                            'widget' => 'select',
                            'optionsFrom' => ['model' => self::class, 'value' => 'id', 'label' => 'title', 'excludeSelf' => true],
                            'help' => 'Set to make this a sub-task.',
                        ],
                        [
                            'field' => 'user_id',
                            'label' => 'Assignee',
                            'widget' => 'select',
                            'optionsFrom' => ['model' => User::class, 'value' => 'id', 'label' => 'name', 'orderBy' => 'name'],
                        ],
                        ['field' => 'kanban_state', 'label' => 'State', 'widget' => 'select', 'options' => [
                            ['value' => 'normal', 'label' => 'In Progress'],
                            ['value' => 'blocked', 'label' => 'Blocked'],
                            ['value' => 'done', 'label' => 'Ready'],
                        ]],
                        ['field' => 'blocked_reason', 'label' => 'Blocked reason', 'widget' => 'text', 'placeholder' => 'Why is this blocked?'],
                        ['field' => 'priority', 'label' => 'Priority', 'widget' => 'checkbox'],
                        ['field' => 'planned_hours', 'label' => 'Planned hours', 'widget' => 'number'],
                        ['field' => 'description', 'label' => 'Description', 'widget' => 'textarea'],
                    ],
                ]),
            ],
        );
    }
}
