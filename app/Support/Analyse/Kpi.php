<?php

namespace App\Support\Analyse;

/**
 * Un indicateur du portefeuille : une valeur du moment, et son évolution
 * depuis la semaine précédente lorsque l'historique permet de la calculer.
 *
 * Aucune cible n'est portée ici : tant que les seuils de performance ne sont
 * pas arrêtés côté métier, l'indicateur se contente de décrire, sans juger.
 */
final readonly class Kpi
{
    /**
     * En deçà de cet écart, l'évolution est traitée comme stable : afficher une
     * flèche pour deux dixièmes de point ferait dire au chiffre plus qu'il ne dit.
     */
    private const SEUIL_STABILITE = 0.5;

    public function __construct(
        public string $libelle,
        public string $valeur,
        public ?string $sousTitre,
        public string $icone,
        public string $couleur,
        public ?float $tendance = null,
        public string $uniteTendance = '',
        public bool $hausseFavorable = true,
        public ?string $tendanceIndisponible = null,
    ) {}

    /**
     * Sens de l'évolution : « hausse », « baisse », « stable », ou null si
     * aucune comparaison n'est possible.
     */
    public function sensTendance(): ?string
    {
        if ($this->tendance === null) {
            return null;
        }

        return match (true) {
            $this->tendance > self::SEUIL_STABILITE => 'hausse',
            $this->tendance < -self::SEUIL_STABILITE => 'baisse',
            default => 'stable',
        };
    }

    /**
     * Une évolution favorable est verte, défavorable rouge, stable neutre.
     *
     * Le sens dépend de l'indicateur : un glissement qui monte est une mauvaise
     * nouvelle, un avancement qui monte une bonne.
     */
    public function couleurTendance(): string
    {
        return match ($this->sensTendance()) {
            'hausse' => $this->hausseFavorable ? 'green' : 'red',
            'baisse' => $this->hausseFavorable ? 'red' : 'green',
            default => 'zinc',
        };
    }

    public function iconeTendance(): string
    {
        return match ($this->sensTendance()) {
            'hausse' => 'arrow-up-right',
            'baisse' => 'arrow-down-right',
            default => 'minus',
        };
    }

    /**
     * Évolution formatée, signe compris.
     */
    public function tendanceFormatee(): ?string
    {
        if ($this->tendance === null) {
            return null;
        }

        return ($this->tendance >= 0 ? '+' : '')
            .number_format($this->tendance, 1, ',', ' ')
            .$this->uniteTendance;
    }
}
