<?php

declare(strict_types=1);

namespace Modules\Project\Models;

use App\Erp\Contracts\DefinesIrModel;
use App\Erp\Registry\FieldDefinition;
use App\Erp\Registry\ModelDefinition;
use App\Erp\Registry\ViewDefinition;
use App\Erp\Translation\TranslatableModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Project\Enums\ProjectStatus;
use Spatie\Translatable\HasTranslations;

/**
 * A project: a named container for tasks across configurable Kanban stages,
 * optionally linked to an analytic account that accrues its labour cost.
 *
 * @property int $id
 * @property string $name           Translatable JSON envelope ({"en":..,"ar":..}).
 * @property string|null $description
 * @property string|null $color     Hex colour for visual grouping on the board.
 * @property int|null $analytic_account_id
 * @property ProjectStatus $status
 */
final class Project extends Model implements DefinesIrModel, TranslatableModel
{
    use HasTranslations;

    protected $table = 'projects';

    /** @var list<string> */
    protected $fillable = ['name', 'description', 'color', 'analytic_account_id', 'status'];

    /** @var list<string> */
    public array $translatable = ['name'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'analytic_account_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Odoo-style auto-provisioning: give every new project its own
        // analytic account so timesheet costs have a home, unless one was
        // supplied or the behaviour is switched off in config. saveQuietly()
        // avoids re-firing model events for the back-link write.
        static::created(function (Project $project): void {
            if ($project->analytic_account_id !== null) {
                return;
            }

            if (! (bool) config('project.analytic.auto_create', true)) {
                return;
            }

            $analytic = AnalyticAccount::query()->create([
                'name' => (string) $project->name,
                'code' => 'PRJ-' . $project->getKey(),
                'active' => true,
            ]);

            $project->forceFill(['analytic_account_id' => $analytic->getKey()])->saveQuietly();
        });
    }

    /**
     * @return BelongsTo<AnalyticAccount, $this>
     */
    public function analyticAccount(): BelongsTo
    {
        return $this->belongsTo(AnalyticAccount::class, 'analytic_account_id');
    }

    /**
     * @return HasMany<ProjectStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(ProjectStage::class, 'project_id')->orderBy('sequence');
    }

    /**
     * @return HasMany<ProjectTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class, 'project_id');
    }

    public static function irModelDefinition(): ModelDefinition
    {
        return new ModelDefinition(
            model: 'project.project',
            name: 'Project',
            class: self::class,
            table: 'projects',
            module: 'project',
            fields: [
                new FieldDefinition('name', 'Name', 'char', required: true, sequence: 10),
                new FieldDefinition('status', 'Status', 'selection', selection: [
                    ['value' => 'active', 'label' => 'Active'],
                    ['value' => 'archived', 'label' => 'Archived'],
                ], sequence: 20),
                new FieldDefinition('color', 'Color', 'char', sequence: 30),
                new FieldDefinition('analytic_account_id', 'Analytic Account', 'many2one', relation: 'analytic.account', sequence: 40),
            ],
            views: [
                new ViewDefinition('Projects', 'list', [
                    'columns' => [
                        ['field' => 'name', 'label' => 'Name', 'sortable' => true],
                        ['field' => 'status', 'label' => 'Status', 'format' => 'badge', 'sortable' => true],
                    ],
                    'default_sort' => [['field' => 'name', 'dir' => 'asc']],
                    'per_page' => 20,
                    // Rows open the Kanban board, not a record form.
                    'open' => '/app/project/{id}/board',
                ]),
                new ViewDefinition('Project', 'form', [
                    'cols' => 2,
                    'fields' => [
                        ['field' => 'name', 'label' => 'Name', 'widget' => 'text', 'required' => true, 'translatable' => true],
                        ['field' => 'description', 'label' => 'Description', 'widget' => 'textarea'],
                        ['field' => 'color', 'label' => 'Color', 'widget' => 'text', 'placeholder' => '#714b67', 'help' => 'Hex colour used to group the project visually.'],
                        ['field' => 'status', 'label' => 'Status', 'widget' => 'select', 'options' => [
                            ['value' => 'active', 'label' => 'Active'],
                            ['value' => 'archived', 'label' => 'Archived'],
                        ]],
                    ],
                ]),
            ],
        );
    }
}
