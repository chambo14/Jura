<?php

namespace App\Support\Analyse;

/**
 * Vue d'ensemble du portefeuille analysé, indépendante de l'axe de regroupement.
 */
final readonly class SyntheseAnalyse
{
    public function __construct(
        public int $projets,
        public int $projetsGlisses,
        public float $glissementMoyen,
        public int $glissementMax,
        public ?float $ecartAvancementMoyen,
        public int $jalonsEvalues,
        public int $jalonsTenus,
        public int $jalonsEnRetard,
        public int $jalonsCritiquesEnRetard,
    ) {}

    /**
     * Part des jalons livrés à la date initialement annoncée, ou en avance.
     */
    public function tauxJalonsTenus(): ?float
    {
        if ($this->jalonsEvalues === 0) {
            return null;
        }

        return round($this->jalonsTenus / $this->jalonsEvalues * 100, 1);
    }

    /**
     * Part des projets dont la date de fin a été repoussée.
     */
    public function partProjetsGlisses(): float
    {
        if ($this->projets === 0) {
            return 0.0;
        }

        return round($this->projetsGlisses / $this->projets * 100, 1);
    }
}
