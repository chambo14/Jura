<?php

namespace App\Enums;

enum FlashReportStatus: string
{
    case Brouillon = 'brouillon';
    case Soumis = 'soumis';
    case Publie = 'publie';

    public function label(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Soumis => 'Soumis à la Direction',
            self::Publie => 'Publié',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Brouillon => 'zinc',
            self::Soumis => 'amber',
            self::Publie => 'green',
        };
    }

    public function isEditable(): bool
    {
        return $this !== self::Publie;
    }
}
