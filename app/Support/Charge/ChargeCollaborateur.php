<?php

namespace App\Support\Charge;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Charge consolidée d'un collaborateur sur une période.
 */
final readonly class ChargeCollaborateur
{
    /**
     * @param  Collection<int, ChargeProjet>  $projets
     */
    public function __construct(
        public User $collaborateur,
        public Collection $projets,
    ) {}

    public function total(): float
    {
        return round($this->projets->sum(fn (ChargeProjet $c) => $c->retenue()), 1);
    }

    public function enSurcharge(): bool
    {
        return $this->total() > 100;
    }

    /**
     * Charge issue d'affectations nominales, hors délégation de tâches.
     */
    public function planifiee(): float
    {
        return round($this->projets->sum(fn (ChargeProjet $c) => $c->planifiee), 1);
    }

    /**
     * Charge portée par des tâches confiées sans affectation nominale.
     */
    public function deleguee(): float
    {
        return round(
            $this->projets->filter(fn (ChargeProjet $c) => $c->parDelegation)
                ->sum(fn (ChargeProjet $c) => $c->retenue()),
            1,
        );
    }

    public function nombreProjets(): int
    {
        return $this->projets->count();
    }

    public function tachesOuvertes(): int
    {
        return (int) $this->projets->sum(fn (ChargeProjet $c) => $c->tachesOuvertes);
    }

    /**
     * Couleur d'alerte : au-delà de 100 % la personne est en surcharge,
     * au-delà de 85 % elle n'a plus de marge.
     */
    public function couleur(): string
    {
        return match (true) {
            $this->total() > 100 => 'red',
            $this->total() > 85 => 'amber',
            default => 'green',
        };
    }
}
