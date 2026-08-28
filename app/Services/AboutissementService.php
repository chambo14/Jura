<?php

namespace App\Services;

use App\Enums\DeliverableStatus;
use App\Enums\ProgressStatus;
use App\Models\Deliverable;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Support\Livrables\PhaseNonAboutie;
use Illuminate\Support\Collection;

/**
 * Aboutissement des phases d'un projet, au regard des pièces produites.
 *
 * Une phase du référentiel MPM attend des livrables : le cadrage doit
 * produire sa note de cadrage. Tant que le projet n'a pas dépassé cette
 * phase, rien ne manque — le travail est en cours. Dès qu'il l'a dépassée,
 * l'absence de la pièce devient un constat : l'étape n'est pas aboutie,
 * quoi qu'en dise le statut déclaré.
 */
class AboutissementService
{
    /**
     * Les phases dépassées dont il manque au moins un livrable obligatoire,
     * dans l'ordre de la méthodologie.
     *
     * Les relations déjà chargées sur le projet sont réutilisées : le tableau
     * de bord diagnostique tout le portefeuille d'un coup, et ne peut pas
     * s'offrir deux requêtes de plus par projet.
     *
     * @return Collection<int, PhaseNonAboutie>
     */
    public function phasesNonAbouties(Project $project): Collection
    {
        $phases = ($project->relationLoaded('phases')
            ? $project->phases->loadMissing('phase')
            : $project->phases()->with('phase')->get())
            ->sortBy('ordre')
            ->values();

        if ($phases->isEmpty()) {
            return collect();
        }

        $livrables = $project->relationLoaded('deliverables')
            ? $project->deliverables->loadMissing('attachments')
            : $project->deliverables()->with('attachments')->get();

        return $phases
            ->filter(fn (ProjectPhase $phase) => $this->depassee($phase, $phases))
            ->map(function (ProjectPhase $phase) use ($livrables) {
                $manquants = $livrables
                    ->where('phase_id', $phase->phase_id)
                    ->filter(fn (Deliverable $livrable) => $this->du($livrable))
                    ->values();

                return new PhaseNonAboutie(
                    phase: $phase->phase,
                    manquants: $manquants,
                    phaseCloturee: $phase->statut === ProgressStatus::Termine,
                );
            })
            ->filter(fn (PhaseNonAboutie $constat) => $constat->manquants->isNotEmpty())
            ->values();
    }

    /**
     * Le projet a-t-il dépassé cette phase ?
     *
     * Soit elle est déclarée terminée, soit une phase postérieure a démarré —
     * on ne cadre plus quand on développe. Une phase annulée n'attend plus
     * rien, et ne fait pas dépasser celles qui la précèdent.
     *
     * @param  Collection<int, ProjectPhase>  $toutes
     */
    private function depassee(ProjectPhase $phase, Collection $toutes): bool
    {
        if ($phase->statut === ProgressStatus::Annule) {
            return false;
        }

        if ($phase->statut === ProgressStatus::Termine) {
            return true;
        }

        return $toutes->contains(
            fn (ProjectPhase $autre) => $autre->ordre > $phase->ordre
                && ! in_array($autre->statut, [ProgressStatus::NonDemarre, ProgressStatus::Annule], true),
        );
    }

    /**
     * Le livrable est-il dû, et manquant ?
     *
     * Un livrable facultatif ne bloque personne, et un livrable marqué
     * « non applicable » a été explicitement écarté du projet.
     */
    private function du(Deliverable $livrable): bool
    {
        return $livrable->obligatoire
            && $livrable->statut !== DeliverableStatus::NonApplicable
            && ! $livrable->estFourni();
    }
}
