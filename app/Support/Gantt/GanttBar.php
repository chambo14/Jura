<?php

namespace App\Support\Gantt;

/**
 * Barre positionnée en pourcentage de la largeur du diagramme.
 */
final readonly class GanttBar
{
    public function __construct(
        public float $left,
        public float $width,
    ) {}
}
