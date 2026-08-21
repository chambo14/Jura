<?php

namespace App\Enums;

enum HealthStatus: string
{
    case Vert = 'vert';
    case Orange = 'orange';
    case Rouge = 'rouge';

    public function label(): string
    {
        return match ($this) {
            self::Vert => 'Sous contrôle',
            self::Orange => 'Sous surveillance',
            self::Rouge => 'En alerte',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Vert => 'green',
            self::Orange => 'amber',
            self::Rouge => 'red',
        };
    }

    public function weight(): int
    {
        return match ($this) {
            self::Rouge => 3,
            self::Orange => 2,
            self::Vert => 1,
        };
    }
}
