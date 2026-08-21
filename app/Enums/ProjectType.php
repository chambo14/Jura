<?php

namespace App\Enums;

enum ProjectType: string
{
    case Deploiement = 'deploiement';
    case Integration = 'integration';
    case Developpement = 'developpement';

    public function label(): string
    {
        return match ($this) {
            self::Deploiement => 'DÉPLOIEMENT',
            self::Integration => 'INTÉGRATION',
            self::Developpement => 'DÉVELOPPEMENT',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Deploiement => 'emerald',
            self::Integration => 'cyan',
            self::Developpement => 'indigo',
        };
    }
}
