<?php

namespace Database\Factories;

use App\Enums\MemberRole;
use App\Enums\UserRole;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'profile_id' => fn () => $this->profilePour(UserRole::Membre)->id,
            'actif' => true,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            /* @chisel-2fa */
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            /* @end-chisel-2fa */
        ];
    }

    /**
     * Rattache l'utilisateur à un profil d'accès livré avec l'application,
     * en le créant au besoin pour que la fabrique reste utilisable sans seeder.
     */
    public function role(UserRole $role): static
    {
        return $this->state(fn (array $attributes) => [
            'profile_id' => $this->profilePour($role)->id,
            'poste' => $role->label(),
            // Le profil dit les droits, la fonction dit le métier. Les deux
            // coïncident pour les profils livrés, sauf la Direction, qui
            // administre sans être partie prenante d'un projet.
            'fonction' => MemberRole::depuisUnPoste($role->label()),
        ]);
    }

    /**
     * Fonction projet du compte, indépendamment de son profil d'accès.
     */
    public function fonction(?MemberRole $fonction): static
    {
        return $this->state(fn (array $attributes) => ['fonction' => $fonction]);
    }

    /**
     * Récupère le profil système correspondant, en le créant si le référentiel
     * n'a pas été semé — la fabrique reste ainsi utilisable seule.
     */
    private function profilePour(UserRole $role): Profile
    {
        return Profile::firstOrCreate(
            ['code' => $role->value],
            [
                'nom' => $role->label(),
                'description' => $role->description(),
                'couleur' => $role->color(),
                'ordre' => $role->ordre(),
                'systeme' => true,
                ...$role->permissions(),
            ],
        );
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        /* @chisel-2fa */
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
        /* @end-chisel-2fa */
    }
}
