<?php

namespace App\Support\Audit;

use Closure;

/**
 * Motif courant des changements journalisés.
 *
 * Une valeur modifiée dit rarement pourquoi elle l'a été. L'occasion, elle,
 * est connue de l'action en cours : un déplacement sur le tableau, la
 * publication d'un flash report, une reprise depuis les cartes. Le motif est
 * posé le temps de l'action et retombe ensuite, pour qu'aucune écriture
 * ultérieure n'hérite d'une justification qui n'est pas la sienne.
 */
class Audit
{
    private static ?string $motif = null;

    /**
     * Exécute une action en attribuant ce motif aux changements qu'elle produit.
     *
     * @template T
     *
     * @param  Closure(): T  $action
     * @return T
     */
    public static function pour(string $motif, Closure $action): mixed
    {
        $precedent = self::$motif;
        self::$motif = $motif;

        try {
            return $action();
        } finally {
            self::$motif = $precedent;
        }
    }

    public static function motif(): ?string
    {
        return self::$motif;
    }

    /** Remet le motif à zéro — utile entre deux tests. */
    public static function oublier(): void
    {
        self::$motif = null;
    }
}
