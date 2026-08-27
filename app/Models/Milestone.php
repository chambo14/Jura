<?php

namespace App\Models;

use App\Concerns\SuitUneEcheance;
use App\Contracts\PorteUneEcheance;
use App\Enums\GovernanceBody;
use App\Enums\ProgressStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $project_phase_id
 * @property string $libelle
 * @property string|null $description
 * @property CarbonInterface $date_prevue
 * @property CarbonInterface|null $date_prevue_initiale
 * @property CarbonInterface|null $date_reelle
 * @property ProgressStatus $statut
 * @property bool $critique
 * @property GovernanceBody|null $instance
 * @property-read Project $project
 * @property-read ProjectPhase|null $projectPhase
 */
#[Fillable([
    'project_id', 'project_phase_id', 'libelle', 'description', 'date_prevue',
    'date_prevue_initiale', 'date_reelle', 'statut', 'critique', 'instance',
])]
class Milestone extends Model implements PorteUneEcheance
{
    use SuitUneEcheance;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'statut' => ProgressStatus::class,
            'instance' => GovernanceBody::class,
            'date_prevue' => 'date',
            'date_prevue_initiale' => 'date',
            'date_reelle' => 'date',
            'critique' => 'boolean',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<ProjectPhase, $this> */
    public function projectPhase(): BelongsTo
    {
        return $this->belongsTo(ProjectPhase::class);
    }

    public function echeance(): ?CarbonInterface
    {
        return $this->date_prevue;
    }

    public function echeanceClose(): bool
    {
        return $this->statut->isClosed();
    }

    /**
     * Glissement en jours par rapport à la date initialement annoncée.
     */
    public function glissementJours(): int
    {
        if (! $this->date_prevue_initiale) {
            return 0;
        }

        return (int) $this->date_prevue_initiale->diffInDays($this->date_prevue, false);
    }

    /**
     * @param  Builder<Milestone>  $query
     * @return Builder<Milestone>
     */
    public function scopeEnRetard(Builder $query): Builder
    {
        return $query->whereNotIn('statut', [ProgressStatus::Termine->value, ProgressStatus::Annule->value])
            ->whereDate('date_prevue', '<', now());
    }
}
