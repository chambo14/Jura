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
 * @property int|null $workflow_step_id
 * @property string|null $libelle
 * @property int $ordre
 * @property ProgressStatus $statut
 * @property string|null $annotation
 * @property CarbonInterface|null $date_reelle
 * @property-read Project $project
 * @property-read WorkflowStep|null $workflowStep
 */
#[Fillable(['project_id', 'workflow_step_id', 'libelle', 'ordre', 'statut', 'annotation', 'date_reelle'])]
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
     * Nom de l'étape sur ce projet.
     *
     * Le libellé propre l'emporte : une étape peut être renommée pour un
     * projet, ou n'exister que pour lui. À défaut, c'est celui du référentiel.
     */
    public function intitule(): string
    {
        $referentiel = $this->workflowStep;

        return match (true) {
            filled($this->libelle) => (string) $this->libelle,
            $referentiel !== null => $referentiel->libelle,
            default => 'Étape sans nom',
        };
    }

    /**
     * Libellé tel qu'affiché sur le bandeau, annotation comprise ("UAT 90 %").
     */
    public function libelleAffiche(): string
    {
        $libelle = $this->intitule();

        return $this->annotation ? "{$libelle} — {$this->annotation}" : $libelle;
    }
}
