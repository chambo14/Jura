<?php

namespace App\Models;

use App\Enums\DeliverableStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property int $phase_id
 * @property int|null $deliverable_type_id
 * @property int|null $responsable_id
 * @property string $nom
 * @property DeliverableStatus $statut
 * @property bool $obligatoire
 * @property CarbonInterface|null $date_prevue
 * @property CarbonInterface|null $date_livraison
 * @property string|null $version
 * @property string|null $lien
 * @property string|null $fichier_path
 * @property string|null $commentaire
 * @property int $ordre
 * @property-read Project $project
 * @property-read Phase $phase
 * @property-read DeliverableType|null $type
 * @property-read User|null $responsable
 */
#[Fillable([
    'project_id', 'phase_id', 'deliverable_type_id', 'responsable_id', 'nom', 'statut',
    'obligatoire', 'date_prevue', 'date_livraison', 'version', 'lien', 'fichier_path',
    'commentaire', 'ordre',
])]
class Deliverable extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'statut' => DeliverableStatus::class,
            'obligatoire' => 'boolean',
            'date_prevue' => 'date',
            'date_livraison' => 'date',
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

    /** @return BelongsTo<DeliverableType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(DeliverableType::class, 'deliverable_type_id');
    }

    /** @return BelongsTo<User, $this> */
    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    /**
     * Le livrable est-il disponible, c'est-à-dire soumis ou validé ?
     */
    public function estDisponible(): bool
    {
        return in_array($this->statut, [DeliverableStatus::Soumis, DeliverableStatus::Valide], true);
    }

    public function enRetard(): bool
    {
        return $this->statut->isOutstanding()
            && $this->date_prevue !== null
            && $this->date_prevue->isPast();
    }

    /**
     * @param  Builder<Deliverable>  $query
     * @return Builder<Deliverable>
     */
    public function scopeDisponibles(Builder $query): Builder
    {
        return $query->whereIn('statut', [
            DeliverableStatus::Soumis->value,
            DeliverableStatus::Valide->value,
        ]);
    }

    /**
     * @param  Builder<Deliverable>  $query
     * @return Builder<Deliverable>
     */
    public function scopeManquants(Builder $query): Builder
    {
        return $query->where('obligatoire', true)
            ->whereIn('statut', [
                DeliverableStatus::AProduire->value,
                DeliverableStatus::EnCours->value,
                DeliverableStatus::Rejete->value,
            ]);
    }
}
