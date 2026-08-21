<?php

namespace App\Models;

use App\Enums\MemberRole;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $project_id
 * @property int $user_id
 * @property MemberRole $role
 * @property int $allocation_pct
 * @property CarbonInterface|null $date_debut
 * @property CarbonInterface|null $date_fin
 * @property string|null $commentaire
 * @property-read Project $project
 * @property-read User $user
 */
#[Fillable(['project_id', 'user_id', 'role', 'allocation_pct', 'date_debut', 'date_fin', 'commentaire'])]
class ProjectMember extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => MemberRole::class,
            'date_debut' => 'date',
            'date_fin' => 'date',
            'allocation_pct' => 'integer',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * L'affectation couvre-t-elle la date donnée ?
     */
    public function estActiveLe(CarbonInterface $date): bool
    {
        return ! ($this->date_debut && $date->lt($this->date_debut))
            && ! ($this->date_fin && $date->gt($this->date_fin));
    }
}
