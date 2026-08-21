<?php

namespace App\Enums;

enum FlashItemType: string
{
    case Realisee = 'realisee';
    case ARealiser = 'a_realiser';
    case Attention = 'attention';

    public function label(): string
    {
        return match ($this) {
            self::Realisee => 'Activités réalisées la semaine antérieure',
            self::ARealiser => 'Activités à réaliser cette semaine',
            self::Attention => "Points d'attention & alertes",
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Realisee => 'Réalisées',
            self::ARealiser => 'À réaliser',
            self::Attention => "Points d'attention",
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Realisee => 'check-circle',
            self::ARealiser => 'calendar-days',
            self::Attention => 'exclamation-triangle',
        };
    }
}
