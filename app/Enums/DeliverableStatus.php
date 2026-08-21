<?php

namespace App\Enums;

enum DeliverableStatus: string
{
    case NonApplicable = 'non_applicable';
    case AProduire = 'a_produire';
    case EnCours = 'en_cours';
    case Soumis = 'soumis';
    case Valide = 'valide';
    case Rejete = 'rejete';

    public function label(): string
    {
        return match ($this) {
            self::NonApplicable => 'Non applicable',
            self::AProduire => 'À produire',
            self::EnCours => 'En cours de rédaction',
            self::Soumis => 'Soumis pour validation',
            self::Valide => 'Validé',
            self::Rejete => 'Rejeté',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NonApplicable => 'zinc',
            self::AProduire => 'orange',
            self::EnCours => 'blue',
            self::Soumis => 'amber',
            self::Valide => 'green',
            self::Rejete => 'red',
        };
    }

    /**
     * A deliverable that still owes work to the project.
     */
    public function isOutstanding(): bool
    {
        return in_array($this, [self::AProduire, self::EnCours, self::Rejete], true);
    }
}
