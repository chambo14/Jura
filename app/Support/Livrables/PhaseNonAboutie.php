<?php

namespace App\Support\Livrables;

use App\Models\Deliverable;
use App\Models\Phase;
use Illuminate\Support\Collection;

/**
 * Une phase que le projet a dépassée sans en avoir versé les livrables.
 *
 * Le constat ne porte pas de jugement sur le travail fait : il dit
 * seulement qu'on ne peut pas le montrer. Une phase de cadrage donnée pour
 * terminée sans note de cadrage laisse le projet sans le document sur
 * lequel toute la suite est censée s'appuyer.
 *
 * @param  Collection<int, Deliverable>  $manquants
 */
readonly class PhaseNonAboutie
{
    /**
     * @param  Collection<int, Deliverable>  $manquants
     */
    public function __construct(
        public Phase $phase,
        public Collection $manquants,
        public bool $phaseCloturee,
    ) {}

    /**
     * Ce qui manque, en clair : « Note de cadrage · Plan projet ».
     */
    public function libelle(): string
    {
        return $this->manquants->map(fn (Deliverable $l) => $l->nom)->implode(' · ');
    }

    /**
     * Une phase déclarée terminée sans ses livrables est une contradiction ;
     * une phase seulement dépassée par la suivante est un retard. Les deux
     * méritent d'être dites, pas avec la même gravité.
     */
    public function grave(): bool
    {
        return $this->phaseCloturee;
    }
}
