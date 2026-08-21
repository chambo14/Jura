<?php

namespace App\Support\Gantt;

use App\Enums\ProgressStatus;
use Carbon\CarbonInterface;

/**
 * Un jalon positionné sur l'échelle temporelle du diagramme.
 */
final readonly class GanttMilestone
{
    public function __construct(
        public string $libelle,
        public CarbonInterface $date,
        public ProgressStatus $statut,
        public bool $critique,
        public bool $retard,
        public float $left,
    ) {}

    public function atteint(): bool
    {
        return $this->statut === ProgressStatus::Termine;
    }
}
