<?php

namespace App\Enums;

/**
 * Position d'un élément planifié par rapport à son échéance.
 *
 * Un booléen « en retard » confond deux situations très différentes : un
 * élément dans les temps, et un élément dont personne ne sait s'il l'est,
 * faute de date. Les écrans affichaient alors « 0 en retard » sur un projet
 * sans aucune échéance saisie — un signal rassurant, et faux.
 */
enum SuiviEcheance: string
{
    case ALHeure = 'a_l_heure';
    case EnRetard = 'en_retard';
    case SansEcheance = 'sans_echeance';
    case NonApplicable = 'non_applicable';

    public function label(): string
    {
        return match ($this) {
            self::ALHeure => 'À l\'heure',
            self::EnRetard => 'En retard',
            self::SansEcheance => 'Sans échéance',
            self::NonApplicable => 'Non applicable',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ALHeure => 'green',
            self::EnRetard => 'red',
            self::SansEcheance => 'amber',
            self::NonApplicable => 'zinc',
        };
    }

    /**
     * L'élément est-il en cours et daté, donc réellement jugeable ?
     */
    public function estMesurable(): bool
    {
        return $this === self::ALHeure || $this === self::EnRetard;
    }
}
