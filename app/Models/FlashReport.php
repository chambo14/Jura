<?php

namespace App\Models;

use App\Concerns\EstAudite;
use App\Enums\FlashItemType;
use App\Enums\FlashReportStatus;
use App\Enums\HealthStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $auteur_id
 * @property CarbonInterface $semaine_du
 * @property CarbonInterface $semaine_au
 * @property FlashReportStatus $statut
 * @property CarbonInterface|null $date_debut
 * @property CarbonInterface|null $date_fin_initiale
 * @property CarbonInterface|null $date_fin_revisee
 * @property float $avancement_pct
 * @property float $taux_realisation_pct
 * @property int|null $workflow_step_id
 * @property int|null $phase_id
 * @property string|null $synthese
 * @property string|null $lien_planning
 * @property HealthStatus|null $sante
 * @property CarbonInterface|null $publie_at
 * @property-read Project $project
 * @property-read User|null $auteur
 * @property-read Collection<int, FlashReportItem> $items
 */
#[Fillable([
    'project_id', 'auteur_id', 'semaine_du', 'semaine_au', 'statut',
    'date_debut', 'date_fin_initiale', 'date_fin_revisee', 'avancement_pct', 'taux_realisation_pct',
    'workflow_step_id', 'phase_id', 'synthese', 'lien_planning', 'sante', 'publie_at',
])]
class FlashReport extends Model
{
    use EstAudite;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'statut' => FlashReportStatus::class,
            'sante' => HealthStatus::class,
            'semaine_du' => 'date',
            'semaine_au' => 'date',
            'date_debut' => 'date',
            'date_fin_initiale' => 'date',
            'date_fin_revisee' => 'date',
            'avancement_pct' => 'float',
            'taux_realisation_pct' => 'float',
            'publie_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auteur_id');
    }

    /** @return BelongsTo<WorkflowStep, $this> */
    public function workflowStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class);
    }

    /** @return BelongsTo<Phase, $this> */
    public function phase(): BelongsTo
    {
        return $this->belongsTo(Phase::class);
    }

    /** @return HasMany<FlashReportItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(FlashReportItem::class)->orderBy('ordre');
    }

    /**
     * Lignes d'une rubrique donnée, dans l'ordre d'affichage.
     *
     * @return Collection<int, FlashReportItem>
     */
    public function itemsOfType(FlashItemType $type): Collection
    {
        return $this->items->where('type', $type)->values();
    }

    /**
     * Intitulé de période tel qu'il apparaît en tête de slide.
     */
    public function periodeLabel(): string
    {
        return 'DU '.$this->semaine_du->format('d/m/Y').' AU '.$this->semaine_au->format('d/m/Y');
    }

    /**
     * @param  Builder<FlashReport>  $query
     * @return Builder<FlashReport>
     */
    public function scopePourSemaine(Builder $query, CarbonInterface $lundi): Builder
    {
        return $query->whereDate('semaine_du', $lundi->toDateString());
    }

    /**
     * Attributs dont chaque variation est consignée au journal d'audit.
     *
     * @return array<int, string>
     */
    public static function attributsAudites(): array
    {
        return [
            'statut',
            'sante',
            'avancement_pct',
            'taux_realisation_pct',
            'date_fin_revisee',
            'workflow_step_id',
            'phase_id',
        ];
    }

    public function projetAudite(): ?int
    {
        return $this->project_id;
    }
}
