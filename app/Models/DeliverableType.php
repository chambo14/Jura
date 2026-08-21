<?php

namespace App\Models;

use App\Enums\ProjectType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Livrable type du référentiel MPM.
 *
 * @property int $id
 * @property int $phase_id
 * @property string $nom
 * @property string|null $responsable
 * @property string|null $description
 * @property bool $obligatoire
 * @property array<int, string>|null $types_projet
 * @property int $ordre
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Phase $phase
 */
#[Fillable(['phase_id', 'nom', 'responsable', 'description', 'obligatoire', 'types_projet', 'ordre'])]
class DeliverableType extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'obligatoire' => 'boolean',
            'types_projet' => 'array',
        ];
    }

    /** @return BelongsTo<Phase, $this> */
    public function phase(): BelongsTo
    {
        return $this->belongsTo(Phase::class);
    }

    /**
     * Un livrable sans restriction explicite s'applique à tous les types de projet.
     */
    public function appliesTo(ProjectType $type): bool
    {
        return blank($this->types_projet) || in_array($type->value, $this->types_projet, true);
    }
}
