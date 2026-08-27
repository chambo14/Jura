<?php

namespace App\Support\Avancement;

use Illuminate\Support\Collection;

/**
 * Ce qu'une équipe a réalisé sur les cartes qui lui reviennent, et comment
 * ce chiffre a bougé au fil des semaines.
 *
 * Deux taux cohabitent, et l'écart entre eux se lit :
 *
 *  - `taux` ne compte que les cartes closes. C'est le seul chiffre qui puisse
 *    être recalculé pour une date passée — d'où la courbe d'évolution ;
 *  - `tauxDeclare` ajoute l'avancement saisi sur les cartes encore ouvertes.
 *
 * Un écart large entre les deux signale un travail entamé partout et fini
 * nulle part : beaucoup de cartes à 60 %, aucune dans la colonne « Terminé ».
 */
class AvancementEquipe
{
    /**
     * @param  Collection<int, PointDAvancement>  $evolution
     */
    public function __construct(
        public readonly string $equipe,
        public readonly float $taux,
        public readonly float $tauxDeclare,
        public readonly int $cartes,
        public readonly int $terminees,
        public readonly int $enRetard,
        public readonly float $chargeTotale,
        public readonly float $chargeFaite,
        public readonly Collection $evolution,
    ) {}

    /**
     * Points de pourcentage gagnés sur la durée couverte par la courbe.
     */
    public function progression(): float
    {
        $premier = $this->evolution->first();
        $dernier = $this->evolution->last();

        if (! $premier instanceof PointDAvancement || ! $dernier instanceof PointDAvancement) {
            return 0.0;
        }

        return round($dernier->taux - $premier->taux, 1);
    }

    /**
     * La courbe en coordonnées SVG, dans une boîte de 100 × 100 dessinée avec
     * `preserveAspectRatio="none"` : l'axe vertical couvre 0 à 100 %, et il
     * est retourné parce qu'en SVG l'origine est en haut.
     */
    public function courbe(int $largeur = 100, int $hauteur = 100): string
    {
        $points = $this->evolution->values();

        if ($points->count() < 2) {
            return '';
        }

        $pas = $largeur / ($points->count() - 1);

        return $points
            ->map(fn (PointDAvancement $point, int $rang) => sprintf(
                '%.2f,%.2f',
                $rang * $pas,
                $hauteur - ($point->taux / 100) * $hauteur,
            ))
            ->implode(' ');
    }
}
