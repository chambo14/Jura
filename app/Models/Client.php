<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $nom
 * @property string|null $sigle
 * @property string|null $pays
 * @property string|null $secteur
 * @property string|null $contact_nom
 * @property string|null $contact_email
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['nom', 'sigle', 'pays', 'secteur', 'contact_nom', 'contact_email'])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function displayName(): string
    {
        return $this->sigle ? "{$this->nom} ({$this->sigle})" : $this->nom;
    }
}
