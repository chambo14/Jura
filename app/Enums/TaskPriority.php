<?php

namespace App\Enums;

/**
 * Priorité d'une carte du tableau. Elle ne pilote aucun calcul : elle sert
 * à trancher l'ordre de passage quand deux tâches se disputent la même
 * semaine.
 */
enum TaskPriority: string
{
    case Basse = 'basse';
    case Normale = 'normale';
    case Haute = 'haute';
    case Critique = 'critique';

    public function label(): string
    {
        return match ($this) {
            self::Basse => 'Basse',
            self::Normale => 'Normale',
            self::Haute => 'Haute',
            self::Critique => 'Critique',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Basse => 'zinc',
            self::Normale => 'blue',
            self::Haute => 'amber',
            self::Critique => 'red',
        };
    }

    /**
     * Rang décroissant, pour classer les cartes à l'intérieur d'une colonne.
     */
    public function poids(): int
    {
        return match ($this) {
            self::Critique => 3,
            self::Haute => 2,
            self::Normale => 1,
            self::Basse => 0,
        };
    }
}
