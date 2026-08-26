<?php

namespace App\Services;

use App\Enums\FlashReportStatus;
use App\Enums\ProjectCategory;
use App\Models\FlashReport;
use App\Models\Project;
use App\Models\User;
use App\Support\Comite\Diapositive;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Séquence des diapositives du comité.
 *
 * L'écran de présentation et l'export PowerPoint la partagent : un rapport
 * absent de l'un ne peut pas apparaître dans l'autre, et l'ordre de passage
 * est le même des deux côtés.
 */
class ComiteBuilder
{
    /**
     * Rapports de la semaine, dans l'ordre de passage au comité : rubrique du
     * sommaire, puis rang réglé sur la fiche projet, puis nom.
     *
     * @return Collection<int, FlashReport>
     */
    public function rapports(User $utilisateur, CarbonInterface $lundi, bool $inclureBrouillons = true): Collection
    {
        return FlashReport::query()
            ->whereIn('project_id', Project::query()->visibleTo($utilisateur)->actifs()->select('id'))
            ->pourSemaine($lundi)
            ->when(
                ! $inclureBrouillons,
                fn ($q) => $q->where('statut', FlashReportStatus::Publie->value),
            )
            ->with([
                'items',
                'auteur',
                'workflowStep',
                'phase',
                'project.client',
                'project.chefProjet',
                'project.backUp',
                'project.sponsor',
                'project.referentTechnique',
                'project.members.user',
                'project.steps.workflowStep',
            ])
            ->get()
            ->sortBy([
                fn (FlashReport $a, FlashReport $b) => $a->project->categorie->rang() <=> $b->project->categorie->rang(),
                fn (FlashReport $a, FlashReport $b) => $a->project->ordre_comite <=> $b->project->ordre_comite,
                fn (FlashReport $a, FlashReport $b) => $a->project->nom <=> $b->project->nom,
            ])
            ->values();
    }

    /**
     * Diapositive de titre, puis pour chaque rubrique du sommaire un
     * intercalaire suivi de ses projets. Une rubrique sans projet est passée.
     *
     * @param  Collection<int, FlashReport>  $rapports
     * @return Collection<int, Diapositive>
     */
    public function diapositives(Collection $rapports): Collection
    {
        /** @var Collection<int, Diapositive> $sequence */
        $sequence = collect([Diapositive::titre()]);

        foreach (ProjectCategory::ordonnees() as $categorie) {
            $projets = $rapports->filter(
                fn (FlashReport $rapport) => $rapport->project->categorie === $categorie,
            )->values();

            if ($projets->isEmpty()) {
                continue;
            }

            $sequence->push(Diapositive::rubrique($categorie, $projets));

            foreach ($projets as $rapport) {
                $sequence->push(Diapositive::projet($categorie, $rapport));
            }
        }

        return $sequence->values();
    }
}
