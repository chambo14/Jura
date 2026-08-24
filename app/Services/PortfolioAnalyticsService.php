<?php

namespace App\Services;

use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use App\Support\Analyse\LigneAxe;
use App\Support\Analyse\SyntheseAnalyse;
use Illuminate\Support\Collection;

/**
 * Lecture décisionnelle du portefeuille : où le temps se perd, et sur quels
 * regroupements la dérive se concentre.
 *
 * Trois indicateurs sont consolidés, tous adossés à des dates déjà saisies :
 *
 *  - le glissement, écart en jours entre la date de fin initiale et la date
 *    révisée — c'est l'engagement pris face au client qui a bougé ;
 *  - l'écart d'avancement, différence entre l'avancement déclaré et celui
 *    attendu au prorata du calendrier — il révèle les projets qui se disent
 *    en bonne voie sans l'être ;
 *  - la tenue des jalons, part de ceux livrés à la date initialement annoncée.
 *
 * Ces indicateurs décrivent des projets, pas des personnes : un glissement se
 * construit à plusieurs — décision client, périmètre revu, dépendance externe.
 * L'axe « chef de projet » sert à repérer où regarder, non à classer les gens.
 */
class PortfolioAnalyticsService
{
    public const AXE_CLIENT = 'client';

    public const AXE_CHEF = 'chef';

    /**
     * Libellé retenu lorsque le regroupement n'est pas renseigné sur un projet.
     */
    private const SANS_RATTACHEMENT = 'Non renseigné';

    /**
     * Portefeuille consolidé par axe de regroupement, du plus dérivant au plus tenu.
     *
     * @return Collection<int, LigneAxe>
     */
    public function parAxe(string $axe, User $user): Collection
    {
        return $this->projets($user)
            ->groupBy(fn (Project $projet) => $this->cle($axe, $projet))
            ->map(fn (Collection $projets) => $this->consolider($axe, $projets))
            ->sortByDesc(fn (LigneAxe $ligne) => $ligne->glissementMoyen)
            ->values();
    }

    /**
     * Vue d'ensemble, tous regroupements confondus.
     */
    public function synthese(User $user): SyntheseAnalyse
    {
        $projets = $this->projets($user);
        $glissements = $projets->map(fn (Project $p) => $p->glissementJours());
        $jalons = $this->jalons($projets);

        return new SyntheseAnalyse(
            projets: $projets->count(),
            projetsGlisses: $glissements->filter(fn (int $j) => $j > 0)->count(),
            glissementMoyen: round($glissements->avg() ?? 0, 1),
            glissementMax: (int) ($glissements->max() ?? 0),
            ecartAvancementMoyen: $this->ecartMoyen($projets),
            jalonsEvalues: $jalons->count(),
            jalonsTenus: $jalons->filter(fn (Milestone $j) => $this->jalonTenu($j))->count(),
            jalonsEnRetard: $this->jalonsEnRetard($projets)->count(),
            jalonsCritiquesEnRetard: $this->jalonsEnRetard($projets)->filter(fn (Milestone $j) => $j->critique)->count(),
        );
    }

    /**
     * Projets sur lesquels porte l'analyse, dans le périmètre visible de l'utilisateur.
     *
     * Les projets clos sont conservés : leur glissement final est l'observation
     * la plus instructive du lot.
     *
     * @return Collection<int, Project>
     */
    private function projets(User $user): Collection
    {
        return Project::query()
            ->visibleTo($user)
            ->with(['client', 'chefProjet', 'milestones'])
            ->get();
    }

    /**
     * @param  Collection<int, Project>  $projets
     */
    private function consolider(string $axe, Collection $projets): LigneAxe
    {
        $premier = $projets->first();
        $glissements = $projets->map(fn (Project $p) => $p->glissementJours());
        $jalons = $this->jalons($projets);

        return new LigneAxe(
            libelle: $this->cle($axe, $premier),
            sousTitre: $this->sousTitre($axe, $premier),
            nombreProjets: $projets->count(),
            glissementMoyen: round($glissements->avg() ?? 0, 1),
            glissementMax: (int) ($glissements->max() ?? 0),
            ecartAvancementMoyen: $this->ecartMoyen($projets),
            jalonsEvalues: $jalons->count(),
            jalonsTenus: $jalons->filter(fn (Milestone $j) => $this->jalonTenu($j))->count(),
            jalonsEnRetard: $this->jalonsEnRetard($projets)->count(),
            jalonsCritiquesEnRetard: $this->jalonsEnRetard($projets)->filter(fn (Milestone $j) => $j->critique)->count(),
        );
    }

    private function cle(string $axe, Project $projet): string
    {
        $libelle = $axe === self::AXE_CHEF
            ? $projet->chefProjet?->name
            : $projet->client->nom;

        return $libelle ?? self::SANS_RATTACHEMENT;
    }

    private function sousTitre(string $axe, Project $projet): ?string
    {
        return $axe === self::AXE_CHEF
            ? $projet->chefProjet?->poste
            : $projet->client->pays;
    }

    /**
     * Écart moyen entre avancement déclaré et avancement attendu à date.
     *
     * Seuls les projets actifs entrent dans le calcul : sur un projet clos,
     * un « attendu à date » n'a plus de sens.
     *
     * @param  Collection<int, Project>  $projets
     */
    private function ecartMoyen(Collection $projets): ?float
    {
        $ecarts = $projets
            ->filter(fn (Project $p) => $p->statut->isActive() && $p->avancementTheoriquePct() !== null)
            ->map(fn (Project $p) => $p->avancement_pct - $p->avancementTheoriquePct());

        return $ecarts->isEmpty() ? null : round($ecarts->avg(), 1);
    }

    /**
     * Jalons exploitables : ceux dont on connaît à la fois l'engagement initial
     * et la date de livraison réelle.
     *
     * @param  Collection<int, Project>  $projets
     * @return Collection<int, Milestone>
     */
    private function jalons(Collection $projets): Collection
    {
        return $projets
            ->flatMap(fn (Project $p) => $p->milestones)
            ->filter(fn (Milestone $j) => $j->date_prevue_initiale !== null && $j->date_reelle !== null)
            ->values();
    }

    /**
     * Jalons encore ouverts dont la date prévue est passée.
     *
     * Complément indispensable à la tenue des jalons : celle-ci ne regarde que
     * le passé livré, alors que le retard en cours est ce sur quoi on peut
     * encore agir.
     *
     * @param  Collection<int, Project>  $projets
     * @return Collection<int, Milestone>
     */
    private function jalonsEnRetard(Collection $projets): Collection
    {
        return $projets
            ->flatMap(fn (Project $p) => $p->milestones)
            ->filter(fn (Milestone $j) => $j->enRetard())
            ->values();
    }

    /**
     * Un jalon est tenu s'il a été livré au plus tard à la date annoncée au départ.
     */
    private function jalonTenu(Milestone $jalon): bool
    {
        return $jalon->date_reelle->lessThanOrEqualTo($jalon->date_prevue_initiale);
    }
}
