<?php

namespace App\Support\Gantt;

use App\Enums\ProgressStatus;

/**
 * Une ligne du diagramme : une phase MPM, sa barre prévue et sa barre réelle.
 */
final readonly class GanttRow
{
    public function __construct(
        public string $libelle,
        public string $couleur,
        public ProgressStatus $statut,
        public float $avancement,
        public int $retard,
        public ?GanttBar $prevu,
        public ?GanttBar $reel,
    ) {}

    public function enRetard(): bool
    {
        return $this->retard > 0;
    }
}
