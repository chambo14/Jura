<?php

namespace App\Support\Echeances;

use App\Contracts\PorteUneEcheance;
use App\Enums\SuiviEcheance;

/**
 * Décompte des échéances d'un lot d'éléments planifiés.
 *
 * Sa raison d'être est le cas où il n'y a rien à compter : un projet dont
 * aucun livrable n'a de date ne compte pas « 0 retard », il n'est pas
 * mesurable. Les écrans doivent pouvoir dire la différence, d'où un objet
 * plutôt qu'un entier.
 */
class Echeances
{
    public function __construct(
        public readonly int $aLHeure = 0,
        public readonly int $enRetard = 0,
        public readonly int $sansEcheance = 0,
        public readonly int $nonApplicable = 0,
    ) {}

    /**
     * @param  iterable<int, PorteUneEcheance>  $elements
     */
    public static function de(iterable $elements): self
    {
        $compte = [
            SuiviEcheance::ALHeure->value => 0,
            SuiviEcheance::EnRetard->value => 0,
            SuiviEcheance::SansEcheance->value => 0,
            SuiviEcheance::NonApplicable->value => 0,
        ];

        foreach ($elements as $element) {
            $compte[$element->suiviEcheance()->value]++;
        }

        return new self(
            aLHeure: $compte[SuiviEcheance::ALHeure->value],
            enRetard: $compte[SuiviEcheance::EnRetard->value],
            sansEcheance: $compte[SuiviEcheance::SansEcheance->value],
            nonApplicable: $compte[SuiviEcheance::NonApplicable->value],
        );
    }

    /** Éléments ouverts, toutes situations confondues. */
    public function ouverts(): int
    {
        return $this->aLHeure + $this->enRetard + $this->sansEcheance;
    }

    /** Éléments ouverts dont l'échéance est connue : le seul socle du calcul. */
    public function mesurables(): int
    {
        return $this->aLHeure + $this->enRetard;
    }

    /**
     * Un décompte de retards a-t-il un sens ?
     *
     * Non quand rien n'est daté alors que des éléments sont ouverts : le
     * chiffre serait un zéro trompeur.
     */
    public function mesurable(): bool
    {
        return $this->mesurables() > 0 || $this->ouverts() === 0;
    }

    /** Ce qu'affiche le compteur : un nombre, ou l'aveu qu'il n'y en a pas. */
    public function valeur(): string
    {
        return $this->mesurable() ? (string) $this->enRetard : 'Non mesurable';
    }

    /** La précision qui accompagne le compteur, quand elle éclaire. */
    public function precision(): ?string
    {
        if (! $this->mesurable()) {
            return $this->ouverts().' '.($this->ouverts() > 1 ? 'éléments ouverts' : 'élément ouvert').', aucune échéance saisie';
        }

        if ($this->sansEcheance > 0) {
            return $this->sansEcheance.' sans échéance, hors du calcul';
        }

        return null;
    }

    /** Couleur du compteur : l'absence de mesure n'est pas une bonne nouvelle. */
    public function couleur(): string
    {
        if (! $this->mesurable()) {
            return 'amber';
        }

        return $this->enRetard > 0 ? 'red' : 'green';
    }
}
