<?php

namespace App\Enums;

/**
 * Instances de gouvernance MPM (cf. glossaire du support de formation).
 */
enum GovernanceBody: string
{
    case Copro = 'copro';
    case Copil = 'copil';
    case Codec = 'codec';
    case Cab = 'cab';
    case ComiteGouvernance = 'comite_gouvernance';

    public function label(): string
    {
        return match ($this) {
            self::Copro => 'COPRO — Comité Projet',
            self::Copil => 'COPIL — Comité de Pilotage',
            self::Codec => 'CODEC — Comité de Décision',
            self::Cab => "CAB — Comité d'Arbitrage pour la mise en production",
            self::ComiteGouvernance => 'Comité de Gouvernance',
        };
    }

    public function acronym(): string
    {
        return match ($this) {
            self::Copro => 'COPRO',
            self::Copil => 'COPIL',
            self::Codec => 'CODEC',
            self::Cab => 'CAB',
            self::ComiteGouvernance => 'COGOUV',
        };
    }

    public function cadence(): string
    {
        return match ($this) {
            self::Copro => 'Hebdomadaire',
            self::Copil => 'Mensuel',
            self::Codec, self::Cab, self::ComiteGouvernance => 'Ponctuel',
        };
    }
}
