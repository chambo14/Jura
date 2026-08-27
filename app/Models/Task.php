<?php

namespace App\Models;

use App\Concerns\HasAttachments;
use App\Concerns\SuitUneEcheance;
use App\Contracts\PorteUneEcheance;
use App\Enums\ProgressStatus;
use App\Enums\TaskPriority;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $project_id
 * @property int|null $project_phase_id
 * @property int|null $assignee_id
 * @property int|null $delegue_par_id
 * @property string $libelle
 * @property string|null $description
 * @property CarbonInterface|null $date_debut
 * @property CarbonInterface|null $date_echeance
 * @property CarbonInterface|null $date_realisation
 * @property float $avancement_pct
 * @property int|null $charge_jours
 * @property ProgressStatus $statut
 * @property TaskPriority $priorite
 * @property int $ordre
 * @property-read Project $project
 * @property-read ProjectPhase|null $projectPhase
 * @property-read User|null $assignee
 * @property-read User|null $deleguePar
 * @property-read Collection<int, TaskComment> $comments
 * @property-read Collection<int, Attachment> $attachments
 */
#[Fillable([
    'project_id', 'project_phase_id', 'assignee_id', 'delegue_par_id', 'libelle', 'description',
    'date_debut', 'date_echeance', 'date_realisation', 'avancement_pct', 'charge_jours', 'statut',
    'priorite', 'ordre',
])]
class Task extends Model implements PorteUneEcheance
{
    use HasAttachments, SuitUneEcheance;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'statut' => ProgressStatus::class,
            'priorite' => TaskPriority::class,
            'date_debut' => 'date',
            'date_echeance' => 'date',
            'date_realisation' => 'date',
            'avancement_pct' => 'float',
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

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return BelongsTo<User, $this> */
    public function deleguePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegue_par_id');
    }

    /** @return HasMany<TaskComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class)->oldest();
    }

    /**
     * Fenêtre de travail de la tâche, utilisée pour répartir sa charge.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}|null
     */
    public function fenetre(): ?array
    {
        $fin = $this->date_echeance ?? $this->date_realisation;

        if (! $fin) {
            return null;
        }

        return [$this->date_debut ?? $fin, $fin];
    }

    public function echeance(): ?CarbonInterface
    {
        return $this->date_echeance;
    }

    public function echeanceClose(): bool
    {
        return $this->statut->isClosed();
    }

    /**
     * Tâches clôturées pendant la fenêtre : alimentent "activités réalisées".
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeRealiseesEntre(Builder $query, CarbonInterface $du, CarbonInterface $au): Builder
    {
        // Les colonnes date sont stockées avec une heure : la comparaison doit porter
        // sur la partie date, sinon le dernier jour de la fenêtre est exclu.
        $debut = $du->toDateString();
        $fin = $au->toDateString();

        return $query->where(function (Builder $q) use ($debut, $fin) {
            $q->where(function (Builder $closes) use ($debut, $fin) {
                $closes->whereDate('date_realisation', '>=', $debut)
                    ->whereDate('date_realisation', '<=', $fin);
            })->orWhere(function (Builder $encours) use ($debut, $fin) {
                $encours->whereNull('date_realisation')
                    ->where('statut', ProgressStatus::EnCours->value)
                    ->whereDate('date_echeance', '>=', $debut)
                    ->whereDate('date_echeance', '<=', $fin);
            });
        });
    }

    /**
     * Tâches ouvertes dont l'échéance tombe dans la fenêtre : alimentent "activités à réaliser".
     *
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public function scopeAttenduesEntre(Builder $query, CarbonInterface $du, CarbonInterface $au): Builder
    {
        return $query->whereNotIn('statut', [ProgressStatus::Termine->value, ProgressStatus::Annule->value])
            ->whereNotNull('date_echeance')
            ->whereDate('date_echeance', '<=', $au->toDateString());
    }
}
