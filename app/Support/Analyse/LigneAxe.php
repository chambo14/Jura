<?php

namespace App\Support\Analyse;

use App\Services\ProjectHealthService;

/**
 * Une ligne d'analyse : un regroupement du portefeuille — un client, un chef de
 * projet — et les indicateurs consolidés des projets qui lui sont rattachés.
 *
 * Les moyennes portent sur des effectifs souvent réduits : un client ne compte
 * parfois qu'un seul projet. L'effectif est donc exposé à côté de chaque
 * moyenne, et {@see effectifFaible()} permet de le signaler à la lecture.
 */
final readonly class LigneAxe
{
    /**
     * En deçà de ce nombre de projets, une moyenne ne dit à peu près rien.
     */
    private const EFFECTIF_MINIMAL = 3;

    public function __construct(
        public string $libelle,
        public ?string $sousTitre,
        public int $nombreProjets,
        public float $glissementMoyen,
        public int $glissementMax,
        public ?float $ecartAvancementMoyen,
        public int $jalonsEvalues,
        public int $jalonsTenus,
        public int $jalonsEnRetard,
        public int $jalonsCritiquesEnRetard,
    ) {}

    /**
     * Effectif trop faible pour qu'une moyenne soit interprétable.
     */
    public function effectifFaible(): bool
    {
        return $this->nombreProjets < self::EFFECTIF_MINIMAL;
    }

    /**
     * Part des jalons livrés à la date initialement annoncée, ou en avance.
     *
     * Null tant qu'aucun jalon du regroupement n'a de date réelle : on ne
     * publie pas un taux calculé sur zéro observation.
     */
    public function tauxJalonsTenus(): ?float
    {
        if ($this->jalonsEvalues === 0) {
            return null;
        }

        return round($this->jalonsTenus / $this->jalonsEvalues * 100, 1);
    }

    /**
     * Couleur du glissement, alignée sur les seuils d'alerte des projets.
     */
    public function couleurGlissement(): string
    {
        return match (true) {
            $this->glissementMoyen >= ProjectHealthService::SEUIL_GLISSEMENT_ROUGE => 'red',
            $this->glissementMoyen >= ProjectHealthService::SEUIL_GLISSEMENT_ORANGE => 'amber',
            default => 'green',
        };
    }

    /**
     * Couleur de l'écart entre avancement déclaré et avancement attendu à date.
     */
    public function couleurEcart(): string
    {
        if ($this->ecartAvancementMoyen === null) {
            return 'zinc';
        }

        return match (true) {
            $this->ecartAvancementMoyen <= -ProjectHealthService::SEUIL_ECART_ROUGE => 'red',
            $this->ecartAvancementMoyen <= -ProjectHealthService::SEUIL_ECART_ORANGE => 'amber',
            default => 'green',
        };
    }
}
