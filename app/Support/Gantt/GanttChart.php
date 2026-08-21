<?php

namespace App\Support\Gantt;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Données prêtes à l'affichage d'un diagramme de Gantt rendu en CSS.
 */
final readonly class GanttChart
{
    /**
     * @param  Collection<int, GanttMonth>  $mois
     * @param  Collection<int, GanttRow>  $lignes
     * @param  Collection<int, GanttMilestone>  $jalons
     * @param  float|null  $aujourdhui  Position du repère « aujourd'hui », null hors fenêtre.
     */
    public function __construct(
        public CarbonInterface $debut,
        public CarbonInterface $fin,
        public int $jours,
        public Collection $mois,
        public Collection $lignes,
        public Collection $jalons,
        public ?float $aujourdhui,
    ) {}

    public function estVide(): bool
    {
        return $this->lignes->isEmpty() && $this->jalons->isEmpty();
    }
}
