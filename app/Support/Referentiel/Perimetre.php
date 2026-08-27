<?php

namespace App\Support\Referentiel;

/**
 * Ce que le référentiel MPM déroule sur un projet donné.
 *
 * Tous les projets ne passent pas par toutes les phases ni par toutes les
 * étapes : un correctif n'a pas de phase d'opportunité, un déploiement
 * interne pas de généralisation. Le périmètre dit ce qui s'applique.
 *
 * Une liste absente vaut « tout le référentiel » — c'est ce que demandent le
 * jeu de démonstration et la resynchronisation, qui ne choisissent rien.
 */
class Perimetre
{
    /**
     * @param  array<int, int>|null  $phases  identifiants de Phase
     * @param  array<int, int>|null  $etapes  identifiants de WorkflowStep
     */
    public function __construct(
        public readonly ?array $phases = null,
        public readonly ?array $etapes = null,
    ) {}

    /** Le référentiel entier, sans restriction. */
    public static function complet(): self
    {
        return new self;
    }

    public function restreintLesPhases(): bool
    {
        return $this->phases !== null;
    }

    public function restreintLesEtapes(): bool
    {
        return $this->etapes !== null;
    }

    /**
     * Identifiants de phases retenus, jamais vide pour une clause `whereIn` :
     * un tableau vide y sélectionnerait tout au lieu de rien.
     *
     * @return array<int, int>
     */
    public function phasesRetenues(): array
    {
        return $this->phases ?: [0];
    }

    /**
     * @return array<int, int>
     */
    public function etapesRetenues(): array
    {
        return $this->etapes ?: [0];
    }
}
