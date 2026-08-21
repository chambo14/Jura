<?php

namespace App\Enums;

/**
 * Shared lifecycle used by phases, workflow steps, milestones and tasks.
 */
enum ProgressStatus: string
{
    case NonDemarre = 'non_demarre';
    case EnCours = 'en_cours';
    case Termine = 'termine';
    case Bloque = 'bloque';
    case Annule = 'annule';

    public function label(): string
    {
        return match ($this) {
            self::NonDemarre => 'Non démarré',
            self::EnCours => 'En cours',
            self::Termine => 'Terminé',
            self::Bloque => 'Bloqué',
            self::Annule => 'Annulé',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NonDemarre => 'zinc',
            self::EnCours => 'blue',
            self::Termine => 'green',
            self::Bloque => 'red',
            self::Annule => 'zinc',
        };
    }

    public function isClosed(): bool
    {
        return in_array($this, [self::Termine, self::Annule], true);
    }
}
