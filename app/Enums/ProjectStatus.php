<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case EnPreparation = 'en_preparation';
    case EnCours = 'en_cours';
    case Suspendu = 'suspendu';
    case Cloture = 'cloture';
    case Abandonne = 'abandonne';

    public function label(): string
    {
        return match ($this) {
            self::EnPreparation => 'En préparation',
            self::EnCours => 'En cours',
            self::Suspendu => 'Suspendu',
            self::Cloture => 'Clôturé',
            self::Abandonne => 'Abandonné',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::EnPreparation => 'zinc',
            self::EnCours => 'blue',
            self::Suspendu => 'orange',
            self::Cloture => 'green',
            self::Abandonne => 'red',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::EnPreparation, self::EnCours, self::Suspendu], true);
    }
}
