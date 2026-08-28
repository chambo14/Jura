<?php

namespace App\Models;

use App\Concerns\EstAudite;
use App\Concerns\HasAttachments;
use App\Concerns\SuitUneEcheance;
use App\Contracts\PorteUneEcheance;
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
class Deliverable extends Model implements PorteUneEcheance
{
    use EstAudite, HasAttachments, SuitUneEcheance;

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

    /**
     * Le livrable est-il réellement versé au projet ?
     *
     * Le statut se déclare ; la pièce, elle, se produit. Un livrable coché
     * « validé » sans document ni lien n'est pas un livrable : c'est une
     * intention. C'est cette distinction qui permet de dire qu'une phase
     * n'est pas aboutie alors même qu'on la donne pour terminée.
     */
    public function estFourni(): bool
    {
        $pieces = $this->relationLoaded('attachments')
            ? $this->attachments->isNotEmpty()
            : $this->attachments()->exists();

        return $pieces || filled($this->lien) || filled($this->fichier_path);
    }

    /**
     * Donné pour produit, mais sans rien à montrer.
     */
    public function pieceManquante(): bool
    {
        return $this->estDisponible() && ! $this->estFourni();
    }

    public function echeance(): ?CarbonInterface
    {
        return $this->date_prevue;
    }

    public function echeanceClose(): bool
    {
        return ! $this->statut->isOutstanding();
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

    /**
     * Attributs dont chaque variation est consignée au journal d'audit.
     *
     * @return array<int, string>
     */
    public static function attributsAudites(): array
    {
        return [
            'statut',
            'obligatoire',
            'date_prevue',
            'date_livraison',
            'responsable_id',
        ];
    }

    public function projetAudite(): ?int
    {
        return $this->project_id;
    }
}
