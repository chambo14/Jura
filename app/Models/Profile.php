<?php

namespace App\Models;

use App\Enums\Permission;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Profil d'accès : un jeu de droits nommé, attribué aux utilisateurs.
 *
 * @property int $id
 * @property string $code
 * @property string $nom
 * @property string|null $description
 * @property string $couleur
 * @property int $ordre
 * @property bool $systeme
 * @property bool $voit_tout_le_portefeuille
 * @property bool $cree_projet
 * @property bool $pilote_projet
 * @property bool $contribue
 * @property bool $redige_flash_report
 * @property bool $gere_utilisateurs
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Collection<int, User> $users
 */
#[Fillable([
    'code', 'nom', 'description', 'couleur', 'ordre', 'systeme',
    'voit_tout_le_portefeuille', 'cree_projet', 'pilote_projet',
    'contribue', 'redige_flash_report', 'gere_utilisateurs',
])]
class Profile extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'systeme' => 'boolean',
            'voit_tout_le_portefeuille' => 'boolean',
            'cree_projet' => 'boolean',
            'pilote_projet' => 'boolean',
            'contribue' => 'boolean',
            'redige_flash_report' => 'boolean',
            'gere_utilisateurs' => 'boolean',
        ];
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function accorde(Permission $permission): bool
    {
        return (bool) $this->getAttribute($permission->value);
    }

    /**
     * Profil sans aucun droit d'écriture : consultation seule.
     */
    public function lectureSeule(): bool
    {
        return ! $this->pilote_projet && ! $this->contribue && ! $this->cree_projet;
    }

    /**
     * @return Collection<int, Permission>
     */
    public function permissionsAccordees(): Collection
    {
        /** @var list<Permission> $accordees */
        $accordees = [];

        foreach (Permission::cases() as $permission) {
            if ($this->accorde($permission)) {
                $accordees[] = $permission;
            }
        }

        return collect($accordees);
    }

    /**
     * Un profil système ne se supprime pas, et un profil encore attribué non plus.
     */
    public function supprimable(): bool
    {
        return ! $this->systeme && $this->users()->doesntExist();
    }

    /**
     * Peut-on lui retirer l'administration sans que plus personne ne puisse
     * gérer les comptes ? Garde-fou contre le verrouillage de l'application.
     */
    public function peutPerdreLAdministration(): bool
    {
        if (! $this->gere_utilisateurs) {
            return true;
        }

        return self::query()
            ->where('gere_utilisateurs', true)
            ->whereKeyNot($this->getKey())
            ->whereHas('users', fn (Builder $q) => $q->where('actif', true))
            ->exists();
    }

    /**
     * @param  Builder<Profile>  $query
     * @return Builder<Profile>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('ordre')->orderBy('nom');
    }
}
