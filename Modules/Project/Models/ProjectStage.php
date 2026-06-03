<?php

declare(strict_types=1);

namespace Modules\Project\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Kanban column for a project (e.g. "To Do", "In Progress", "Done").
 * Ordered left-to-right by `sequence`.
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property int $sequence
 */
final class ProjectStage extends Model
{
    protected $table = 'project_stages';

    /** @var list<string> */
    protected $fillable = ['project_id', 'name', 'sequence'];

    /** @var array<string, mixed> */
    protected $attributes = ['sequence' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
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
     * Tasks in this column, in board order.
     *
     * @return HasMany<ProjectTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class, 'stage_id')->orderBy('sequence');
    }
}
