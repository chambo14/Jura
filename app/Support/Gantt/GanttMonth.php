<?php

namespace App\Support\Gantt;

/**
 * Une colonne du bandeau des mois.
 */
final readonly class GanttMonth
{
    public function __construct(
        public string $label,
        public float $left,
        public float $width,
    ) {}
}
