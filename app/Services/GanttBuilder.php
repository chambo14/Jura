<?php

namespace App\Services;

use App\Models\Project;
use App\Support\Gantt\GanttBar;
use App\Support\Gantt\GanttChart;
use App\Support\Gantt\GanttMilestone;
use App\Support\Gantt\GanttMonth;
use App\Support\Gantt\GanttRow;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Convertit les phases et jalons d'un projet en barres positionnées en pourcentage,
 * pour un diagramme de Gantt rendu en CSS sans dépendance externe.
 */
class GanttBuilder
{
    /**
     * Largeur minimale de la fenêtre affichée, pour rester lisible sur un projet court.
     */
    private const FENETRE_MINIMALE_JOURS = 60;

    public function construire(Project $project): GanttChart
    {
        $project->loadMissing(['phases.phase', 'milestones']);

        [$debut, $fin] = $this->fenetre($project);
        $jours = max(1, (int) $debut->diffInDays($fin));

        return new GanttChart(
            debut: $debut,
            fin: $fin,
            jours: $jours,
            mois: $this->bandeauMois($debut, $fin, $jours),
            lignes: $this->lignes($project, $debut, $jours),
            jalons: $this->jalons($project, $debut, $jours),
            aujourdhui: $this->position(now(), $debut, $jours),
        );
    }

    /**
     * Fenêtre temporelle englobant toutes les dates connues du projet.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function fenetre(Project $project): array
    {
        /** @var Collection<int, CarbonInterface> $dates */
        $dates = collect([
            $project->date_debut,
            $project->date_fin_initiale,
            $project->date_fin_revisee,
        ]);

        foreach ($project->phases as $phase) {
            $dates->push($phase->date_debut_prevue, $phase->date_fin_prevue, $phase->date_debut_reelle, $phase->date_fin_reelle);
        }

        foreach ($project->milestones as $jalon) {
            $dates->push($jalon->date_prevue, $jalon->date_reelle);
        }

        $connues = $dates->filter();

        if ($connues->isEmpty()) {
            $debut = now()->startOfMonth();

            return [$debut, $debut->addMonths(6)];
        }

        $debut = $connues->min()->startOfMonth();
        $fin = $connues->max()->endOfMonth();

        if ($debut->diffInDays($fin) < self::FENETRE_MINIMALE_JOURS) {
            $fin = $debut->addDays(self::FENETRE_MINIMALE_JOURS);
        }

        return [$debut, $fin];
    }

    /**
     * @return Collection<int, GanttMonth>
     */
    private function bandeauMois(CarbonInterface $debut, CarbonInterface $fin, int $jours): Collection
    {
        /** @var Collection<int, GanttMonth> $mois */
        $mois = collect();
        $curseur = $debut->startOfMonth();

        while ($curseur->lte($fin)) {
            $finMois = $curseur->endOfMonth()->min($fin);

            $mois->push(new GanttMonth(
                label: $curseur->translatedFormat('M y'),
                left: $this->position($curseur, $debut, $jours) ?? 0.0,
                width: round($curseur->diffInDays($finMois) / $jours * 100, 4),
            ));

            $curseur = $curseur->addMonth()->startOfMonth();
        }

        return $mois;
    }

    /**
     * Une ligne par phase MPM, avec la barre prévue et la barre réelle.
     *
     * @return Collection<int, GanttRow>
     */
    private function lignes(Project $project, CarbonInterface $debut, int $jours): Collection
    {
        /** @var Collection<int, GanttRow> $lignes */
        $lignes = collect();

        foreach ($project->phases as $projectPhase) {
            $prevu = $this->barre($projectPhase->date_debut_prevue, $projectPhase->date_fin_prevue, $debut, $jours);
            $reel = $this->barre($projectPhase->date_debut_reelle, $projectPhase->date_fin_reelle, $debut, $jours);

            // Une phase sans aucune date connue n'a pas de barre à tracer.
            if (! $prevu && ! $reel) {
                continue;
            }

            $lignes->push(new GanttRow(
                libelle: $projectPhase->phase->nom,
                couleur: $projectPhase->phase->couleur,
                statut: $projectPhase->statut,
                avancement: $projectPhase->avancement_pct,
                retard: $projectPhase->retardJours(),
                prevu: $prevu,
                reel: $reel,
            ));
        }

        return $lignes;
    }

    /**
     * @return Collection<int, GanttMilestone>
     */
    private function jalons(Project $project, CarbonInterface $debut, int $jours): Collection
    {
        /** @var Collection<int, GanttMilestone> $jalons */
        $jalons = collect();

        foreach ($project->milestones as $jalon) {
            $date = $jalon->date_reelle ?? $jalon->date_prevue;
            $position = $this->position($date, $debut, $jours);

            // Jalon hors de la fenêtre affichée : rien à positionner.
            if ($position === null) {
                continue;
            }

            $jalons->push(new GanttMilestone(
                libelle: $jalon->libelle,
                date: $date,
                statut: $jalon->statut,
                critique: $jalon->critique,
                retard: $jalon->enRetard(),
                left: $position,
            ));
        }

        return $jalons;
    }

    private function barre(?CarbonInterface $du, ?CarbonInterface $au, CarbonInterface $origine, int $jours): ?GanttBar
    {
        if (! $du && ! $au) {
            return null;
        }

        $du ??= $au;
        $au ??= $du;

        $left = $this->position($du, $origine, $jours) ?? 0.0;
        $width = max(0.6, round($du->diffInDays($au) / $jours * 100, 4));

        return new GanttBar($left, min($width, 100 - $left));
    }

    /**
     * Position d'une date en pourcentage de la fenêtre ; null si elle en sort.
     */
    private function position(?CarbonInterface $date, CarbonInterface $origine, int $jours): ?float
    {
        if (! $date) {
            return null;
        }

        $offset = $origine->diffInDays($date, false);

        if ($offset < 0 || $offset > $jours) {
            return null;
        }

        return round($offset / $jours * 100, 4);
    }
}
