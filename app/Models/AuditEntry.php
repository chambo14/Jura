<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Une variation d'un attribut suivi, telle qu'elle s'est produite.
 *
 * Une ligne du journal ne se modifie pas et ne se supprime pas : c'est ce qui
 * lui donne sa valeur. Elle ne porte qu'un horodatage de création.
 *
 * @property int $id
 * @property string $auditable_type
 * @property int $auditable_id
 * @property int|null $project_id
 * @property int|null $auteur_id
 * @property string $attribut
 * @property string|null $ancienne_valeur
 * @property string|null $nouvelle_valeur
 * @property string|null $motif
 * @property CarbonInterface|null $created_at
 * @property-read Project|null $project
 * @property-read User|null $auteur
 */
#[Fillable([
    'project_id', 'auteur_id', 'attribut', 'ancienne_valeur', 'nouvelle_valeur', 'motif',
])]
class AuditEntry extends Model
{
    public const UPDATED_AT = null;

    /** @return MorphTo<Model, $this> */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
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

    /**
     * Nom lisible de l'objet modifié, sans avoir à le charger quand il a
     * disparu depuis.
     */
    public function objet(): string
    {
        return match ($this->auditable_type) {
            Project::class => 'Projet',
            Task::class => 'Carte',
            Deliverable::class => 'Livrable',
            Milestone::class => 'Jalon',
            FlashReport::class => 'Flash report',
            User::class => 'Compte',
            default => class_basename($this->auditable_type),
        };
    }

    /**
     * @param  Builder<AuditEntry>  $query
     * @return Builder<AuditEntry>
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
