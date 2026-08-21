<?php

namespace App\Support\Charge;

use App\Enums\MemberRole;
use App\Models\Project;

/**
 * Charge d'un collaborateur sur un projet, pour une période donnée.
 *
 * Deux lectures coexistent : la charge planifiée, issue de l'affectation
 * nominale pondérée par le rôle, et la charge assignée, issue des tâches
 * réellement portées. On retient la plus élevée des deux, pour ne pas compter
 * deux fois le même travail tout en voyant les tâches déléguées à quelqu'un
 * qui n'a aucune allocation nominale.
 */
final readonly class ChargeProjet
{
    public function __construct(
        public Project $projet,
        public ?MemberRole $role,
        public float $planifiee,
        public float $assignee,
        public int $tachesOuvertes,
        public bool $parDelegation,
    ) {}

    public function retenue(): float
    {
        return round(max($this->planifiee, $this->assignee), 1);
    }

    /**
     * La charge de ce projet vient-elle des tâches plutôt que de l'affectation ?
     */
    public function porteeParLesTaches(): bool
    {
        return $this->assignee > $this->planifiee;
    }
}
