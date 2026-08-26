<?php

namespace App\Models;

use App\Enums\Permission;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property int|null $profile_id
 * @property string|null $poste
 * @property string|null $equipe
 * @property string|null $telephone
 * @property bool $actif
 * @property CarbonInterface|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonInterface|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Profile|null $profile
 */
#[Fillable(['name', 'email', 'password', 'profile_id', 'poste', 'equipe', 'telephone', 'actif'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'actif' => 'boolean',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /** @return HasMany<Project, $this> */
    public function projetsPilotes(): HasMany
    {
        return $this->hasMany(Project::class, 'chef_projet_id');
    }

    /** @return HasMany<ProjectMember, $this> */
    public function affectations(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    /** @return HasMany<Task, $this> */
    public function taches(): HasMany
    {
        return $this->hasMany(Task::class, 'assignee_id');
    }

    /** @return BelongsTo<Profile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /**
     * Le profil accorde-t-il ce droit ? Un utilisateur sans profil n'a aucun droit.
     */
    public function peut(Permission $permission): bool
    {
        return (bool) $this->profile?->accorde($permission);
    }

    /**
     * Voit-il l'intégralité du portefeuille, ou seulement ses affectations ?
     */
    public function voitToutLePortefeuille(): bool
    {
        return $this->peut(Permission::VoitToutLePortefeuille);
    }

    /**
     * Peut ouvrir un nouveau projet.
     */
    public function peutPiloter(): bool
    {
        return $this->peut(Permission::CreeProjet);
    }

    /**
     * Profil en consultation seule : aucune écriture, quel que soit le projet.
     */
    public function estLecteur(): bool
    {
        return $this->profile === null || $this->profile->lectureSeule();
    }

    /**
     * Administre les comptes et les profils d'accès.
     */
    public function gereLesUtilisateurs(): bool
    {
        return $this->peut(Permission::GereUtilisateurs);
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeActifs(Builder $query): Builder
    {
        return $query->where('actif', true);
    }
}
