<?php

namespace App\Concerns;

use App\Enums\SuiviEcheance;

/**
 * Situe un élément planifié par rapport à son échéance.
 *
 * Un élément clos n'a plus d'échéance à tenir, et un élément sans date ne
 * peut pas être jugé : ces deux cas se distinguent d'un élément dans les
 * temps, alors qu'un simple booléen les confondait.
 *
 * Les modèles qui l'utilisent apportent `echeance()` et `echeanceClose()`,
 * et déclarent l'interface PorteUneEcheance.
 */
trait SuitUneEcheance
{
    public function suiviEcheance(): SuiviEcheance
    {
        if ($this->echeanceClose()) {
            return SuiviEcheance::NonApplicable;
        }

        $echeance = $this->echeance();

        if ($echeance === null) {
            return SuiviEcheance::SansEcheance;
        }

        return $echeance->isPast() ? SuiviEcheance::EnRetard : SuiviEcheance::ALHeure;
    }

    public function enRetard(): bool
    {
        return $this->suiviEcheance() === SuiviEcheance::EnRetard;
    }

    /**
     * Élément ouvert dont l'échéance n'est pas renseignée : ni en retard, ni
     * à l'heure — simplement invérifiable.
     */
    public function echeanceInconnue(): bool
    {
        return $this->suiviEcheance() === SuiviEcheance::SansEcheance;
    }
}
