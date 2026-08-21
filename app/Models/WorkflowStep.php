<?php

namespace App\Models;

use App\Enums\ProjectType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Étape du bandeau "PHASE ACTUELLE DU PROJET", propre à un type de projet.
 *
 * @property int $id
 * @property ProjectType $type_projet
 * @property int|null $phase_id
 * @property string $libelle
 * @property int $ordre
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Phase|null $phase
 */
#[Fillable(['type_projet', 'phase_id', 'libelle', 'ordre'])]
class WorkflowStep extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type_projet' => ProjectType::class,
        ];
    }

    /** @return BelongsTo<Phase, $this> */
    public function phase(): BelongsTo
    {
        return $this->belongsTo(Phase::class);
    }

    /**
     * @param  Builder<WorkflowStep>  $query
     * @return Builder<WorkflowStep>
     */
    public function scopeForType(Builder $query, ProjectType $type): Builder
    {
        return $query->where('type_projet', $type->value)->orderBy('ordre');
    }
}
