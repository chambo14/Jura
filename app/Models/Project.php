<?php

namespace App\Models;

use App\Enums\ProjectCategory;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use Carbon\CarbonInterface;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property string $code
 * @property string $nom
 * @property string|null $description
 * @property int $client_id
 * @property ProjectType $type_projet
 * @property ProjectCategory $categorie
 * @property int $ordre_comite
 * @property string|null $pays_domiciliation
 * @property ProjectStatus $statut
 * @property CarbonInterface|null $date_debut
 * @property CarbonInterface|null $date_fin_initiale
 * @property CarbonInterface|null $date_fin_revisee
 * @property float $avancement_pct
 * @property float $taux_realisation_pct
 * @property string|null $lien_planning
 * @property int|null $phase_id
 * @property int|null $workflow_step_id
 * @property int|null $chef_projet_id
 * @property int|null $back_up_id
 * @property int|null $sponsor_id
 * @property int|null $referent_technique_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Client $client
 * @property-read Phase|null $phase
 * @property-read WorkflowStep|null $workflowStep
 * @property-read Collection<int, ProjectPhase> $phases
 * @property-read Collection<int, ProjectStep> $steps
 * @property-read Collection<int, Deliverable> $deliverables
 * @property-read Collection<int, Task> $tasks
 * @property-read Collection<int, Milestone> $milestones
 * @property-read Collection<int, ProjectMember> $members
 * @property-read Collection<int, FlashReport> $flashReports
 */
#[Fillable([
    'code', 'nom', 'description', 'client_id', 'type_projet', 'categorie', 'ordre_comite',
    'pays_domiciliation', 'statut',
    'date_debut', 'date_fin_initiale', 'date_fin_revisee', 'avancement_pct', 'taux_realisation_pct',
    'lien_planning', 'phase_id', 'workflow_step_id',
    'chef_projet_id', 'back_up_id', 'sponsor_id', 'referent_technique_id',
])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * Ordre des rubriques du sommaire, en SQL littéral pour rester triable en base.
     *
     * Doit refléter ProjectCategory::rang() ; l'invariant est vérifié par
     * ProjectComiteOrderTest.
     */
    private const ORDRE_RUBRIQUES = "CASE categorie
        WHEN 'monetique' THEN 1
        WHEN 'banque_digitale' THEN 2
        WHEN 'pv_recette' THEN 3
        WHEN 'autres' THEN 4
        ELSE 99 END";

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type_projet' => ProjectType::class,
            'categorie' => ProjectCategory::class,
            'statut' => ProjectStatus::class,
            'date_debut' => 'date',
            'date_fin_initiale' => 'date',
            'date_fin_revisee' => 'date',
            'avancement_pct' => 'float',
            'taux_realisation_pct' => 'float',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Phase, $this> */
    public function phase(): BelongsTo
    {
        return $this->belongsTo(Phase::class);
    }

    /** @return BelongsTo<WorkflowStep, $this> */
    public function workflowStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class);
    }

    /** @return BelongsTo<User, $this> */
    public function chefProjet(): BelongsTo
    {
        return $this->belongsTo(User::class, 'chef_projet_id');
    }

    /** @return BelongsTo<User, $this> */
    public function backUp(): BelongsTo
    {
        return $this->belongsTo(User::class, 'back_up_id');
    }

    /** @return BelongsTo<User, $this> */
    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sponsor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function referentTechnique(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referent_technique_id');
    }

    /** @return HasMany<ProjectMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    /** @return HasMany<ProjectPhase, $this> */
    public function phases(): HasMany
    {
        return $this->hasMany(ProjectPhase::class)->orderBy('ordre');
    }

    /** @return HasMany<ProjectStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(ProjectStep::class)->orderBy('ordre');
    }

    /** @return HasMany<Milestone, $this> */
    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class)->orderBy('date_prevue');
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /** @return HasMany<Deliverable, $this> */
    public function deliverables(): HasMany
    {
        return $this->hasMany(Deliverable::class);
    }

    /** @return HasMany<FlashReport, $this> */
    public function flashReports(): HasMany
    {
        return $this->hasMany(FlashReport::class)->orderByDesc('semaine_du');
    }

    /**
     * Date de fin qui fait foi : la date révisée si elle existe, sinon l'initiale.
     */
    public function dateFinEffective(): ?CarbonInterface
    {
        return $this->date_fin_revisee ?? $this->date_fin_initiale;
    }

    /**
     * Glissement en jours entre la date de fin initiale et la date révisée.
     */
    public function glissementJours(): int
    {
        if (! $this->date_fin_initiale || ! $this->date_fin_revisee) {
            return 0;
        }

        return (int) $this->date_fin_initiale->diffInDays($this->date_fin_revisee, false);
    }

    /**
     * Nombre de jours restants avant l'échéance ; négatif si l'échéance est dépassée.
     */
    public function joursRestants(): ?int
    {
        $fin = $this->dateFinEffective();

        return $fin ? (int) now()->startOfDay()->diffInDays($fin, false) : null;
    }

    /**
     * Avancement théorique attendu à date, au prorata du calendrier.
     */
    public function avancementTheoriquePct(): ?float
    {
        if (! $this->date_debut || ! ($fin = $this->dateFinEffective())) {
            return null;
        }

        $total = $this->date_debut->diffInDays($fin);

        if ($total <= 0) {
            return 100.0;
        }

        $ecoule = $this->date_debut->diffInDays(now(), false);

        return round(max(0, min(100, $ecoule / $total * 100)), 2);
    }

    /**
     * Ordre de passage au comité : rubrique du sommaire, puis rang dans la rubrique.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopeOrdreComite(Builder $query): Builder
    {
        return $query
            ->orderByRaw(self::ORDRE_RUBRIQUES)
            ->orderBy('ordre_comite')
            ->orderBy('nom');
    }

    /**
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopeActifs(Builder $query): Builder
    {
        return $query->whereIn('statut', [
            ProjectStatus::EnPreparation->value,
            ProjectStatus::EnCours->value,
            ProjectStatus::Suspendu->value,
        ]);
    }

    /**
     * Projets visibles par un utilisateur donné.
     *
     * @param  Builder<Project>  $query
     * @return Builder<Project>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->voitToutLePortefeuille()) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('chef_projet_id', $user->id)
                ->orWhere('back_up_id', $user->id)
                ->orWhere('sponsor_id', $user->id)
                ->orWhere('referent_technique_id', $user->id)
                ->orWhereHas('members', fn (Builder $m) => $m->where('user_id', $user->id));
        });
    }
}
