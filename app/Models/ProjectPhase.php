<?php

namespace App\Models;

use App\Enums\ProgressStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $project_id
 * @property int $phase_id
 * @property int $ordre
 * @property CarbonInterface|null $date_debut_prevue
 * @property CarbonInterface|null $date_fin_prevue
 * @property CarbonInterface|null $date_debut_reelle
 * @property CarbonInterface|null $date_fin_reelle
 * @property float $avancement_pct
 * @property ProgressStatus $statut
 * @property string|null $commentaire
 * @property-read Project $project
 * @property-read Phase $phase
 */
#[Fillable([
    'project_id', 'phase_id', 'ordre', 'date_debut_prevue', 'date_fin_prevue',
    'date_debut_reelle', 'date_fin_reelle', 'avancement_pct', 'statut', 'commentaire',
])]
class ProjectPhase extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'statut' => ProgressStatus::class,
            'date_debut_prevue' => 'date',
            'date_fin_prevue' => 'date',
            'date_debut_reelle' => 'date',
            'date_fin_reelle' => 'date',
            'avancement_pct' => 'float',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Phase, $this> */
    public function phase(): BelongsTo
    {
        return $this->belongsTo(Phase::class);
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** @return HasMany<Milestone, $this> */
    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class);
    }

    /**
     * Phase non clôturée dont la fin prévue est dépassée.
     */
    public function enRetard(): bool
    {
        return ! $this->statut->isClosed()
            && $this->date_fin_prevue !== null
            && $this->date_fin_prevue->isPast();
    }

    /**
     * Retard en jours sur la fin prévue ; 0 si la phase est dans les temps.
     */
    public function retardJours(): int
    {
        if (! $this->enRetard()) {
            return 0;
        }

        return (int) $this->date_fin_prevue->diffInDays(now()->startOfDay(), false);
    }
}
