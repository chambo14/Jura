<?php

namespace App\Models;

use App\Enums\ProgressStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property int $workflow_step_id
 * @property int $ordre
 * @property ProgressStatus $statut
 * @property string|null $annotation
 * @property CarbonInterface|null $date_reelle
 * @property-read Project $project
 * @property-read WorkflowStep $workflowStep
 */
#[Fillable(['project_id', 'workflow_step_id', 'ordre', 'statut', 'annotation', 'date_reelle'])]
class ProjectStep extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'statut' => ProgressStatus::class,
            'date_reelle' => 'date',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<WorkflowStep, $this> */
    public function workflowStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class);
    }

    /**
     * Libellé tel qu'affiché sur le bandeau, annotation comprise ("UAT 90 %").
     */
    public function libelleAffiche(): string
    {
        $libelle = $this->workflowStep->libelle;

        return $this->annotation ? "{$libelle} — {$this->annotation}" : $libelle;
    }
}
