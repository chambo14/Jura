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
     * Projets actifs du périmètre dont le flash report de la semaine manque.
     *
     * Ils passent quand même au comité. L'écran ne partait que des rapports
     * existants : un projet dont personne n'avait rendu compte disparaissait
     * silencieusement de l'ordre du jour — précisément celui dont il aurait
     * fallu parler.
     *
     * @param  Collection<int, FlashReport>  $rapports
     * @return Collection<int, Project>
     */
    public function projetsSansRapport(User $utilisateur, Collection $rapports): Collection
    {
        return Project::query()
            ->visibleTo($utilisateur)
            ->actifs()
            ->whereNotIn('id', $rapports->pluck('project_id')->all())
            ->with(['client', 'chefProjet', 'phase', 'workflowStep'])
            ->ordreComite()
            ->get();
    }

    /**
     * La séquence complète du comité pour une semaine : les rapports rendus et
     * les projets qui n'en ont pas. Un seul point d'entrée, pour que l'écran et
     * l'export ne puissent pas diverger.
     *
     * @return Collection<int, Diapositive>
     */
    public function sequence(User $utilisateur, CarbonInterface $lundi, bool $inclureBrouillons = true): Collection
    {
        $rapports = $this->rapports($utilisateur, $lundi, $inclureBrouillons);

        return $this->diapositives($rapports, $this->projetsSansRapport($utilisateur, $rapports));
    }

    /**
     * Diapositive de titre, puis pour chaque rubrique du sommaire un
     * intercalaire suivi de ses projets — d'abord ceux qui ont rendu un
     * rapport, puis ceux dont il manque. Une rubrique entièrement vide est
     * passée.
     *
     * @param  Collection<int, FlashReport>  $rapports
     * @param  Collection<int, Project>|null  $projetsSansRapport
     * @return Collection<int, Diapositive>
     */
    public function diapositives(Collection $rapports, ?Collection $projetsSansRapport = null): Collection
    {
        $manquants = $projetsSansRapport ?? collect();

        /** @var Collection<int, Diapositive> $sequence */
        $sequence = collect([Diapositive::titre()]);

        foreach (ProjectCategory::ordonnees() as $categorie) {
            $projets = $rapports->filter(
                fn (FlashReport $rapport) => $rapport->project->categorie === $categorie,
            )->values();

            $sans = $manquants->filter(
                fn (Project $projet) => $projet->categorie === $categorie,
            )->values();

            if ($projets->isEmpty() && $sans->isEmpty()) {
                continue;
            }

            $sequence->push(Diapositive::rubrique($categorie, $projets, $sans));

            foreach ($projets as $rapport) {
                $sequence->push(Diapositive::projet($categorie, $rapport));
            }

            foreach ($sans as $projet) {
                $sequence->push(Diapositive::sansRapport($categorie, $projet));
            }
        }

        return $sequence->values();
    }
}
