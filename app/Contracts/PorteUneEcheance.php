<?php

namespace App\Contracts;

use App\Concerns\SuitUneEcheance;
use App\Enums\SuiviEcheance;
use Carbon\CarbonInterface;

/**
 * Élément planifié dont on peut dire s'il tient son échéance.
 *
 * Le contrat existe pour que les compteurs sachent ce qu'ils comptent :
 * livrables, jalons et cartes se comptent ensemble ou séparément, mais
 * toujours selon les mêmes quatre situations.
 *
 * @see SuitUneEcheance l'implémentation partagée
 */
interface PorteUneEcheance
{
    /** La date à tenir, quand elle est connue. */
    public function echeance(): ?CarbonInterface;

    /** L'élément est-il sorti du suivi — livré, terminé, annulé ? */
    public function echeanceClose(): bool;

    public function suiviEcheance(): SuiviEcheance;

    public function enRetard(): bool;

    public function echeanceInconnue(): bool;
}
