<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une des 8 phases de la Méthodologie Projet MEDIASOFT.
 *
 * @property int $id
 * @property string $code
 * @property string $nom
 * @property string|null $description
 * @property int $ordre
 * @property string $couleur
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['code', 'nom', 'description', 'ordre', 'couleur'])]
class Phase extends Model
{
    /** @return HasMany<DeliverableType, $this> */
    public function deliverableTypes(): HasMany
    {
        return $this->hasMany(DeliverableType::class)->orderBy('ordre');
    }

    /** @return HasMany<ProjectPhase, $this> */
    public function projectPhases(): HasMany
    {
        return $this->hasMany(ProjectPhase::class);
    }

    /**
     * @param  Builder<Phase>  $query
     * @return Builder<Phase>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('ordre');
    }
}
