<?php

namespace App\Services;

use App\Enums\MemberRole;
use App\Enums\ProgressStatus;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use App\Support\Charge\ChargeCollaborateur;
use App\Support\Charge\ChargeProjet;
use App\Support\Charge\Periode;
use Illuminate\Support\Collection;

/**
 * Calcule le plan de charge des collaborateurs.
 *
 * Deux sources de charge coexistent :
 *
 *  - l'affectation nominale, pondérée par le poids du rôle (un sponsor ne
 *    consomme pas un temps plein) et ramenée au prorata de la période ;
 *  - les tâches réellement assignées, converties en pourcentage d'un temps
 *    plein à partir de leur charge en jours.
 *
 * Sur un même projet on retient la plus élevée des deux, pour ne pas compter
 * deux fois le même travail : une personne allouée à 50 % à qui l'on confie
 * des tâches reste à 50 %, tandis qu'une personne sans allocation à qui un
 * référent technique délègue des tâches apparaît enfin dans le plan.
 */
class ChargeService
{
    /**
     * Charge en jours retenue par défaut pour une tâche dont la charge n'est
     * pas renseignée. Volontairement basse : elle ne doit pas écraser les
     * charges saisies.
     */
    private const CHARGE_JOURS_PAR_DEFAUT = 1;

    /**
     * Charge de tous les collaborateurs concernés sur une période.
     *
     * @return Collection<int, ChargeCollaborateur>
     */
    public function pourPeriode(Periode $periode): Collection
    {
        $affectations = $this->affectations();
        $taches = $this->taches();

        $identifiants = $affectations->pluck('user_id')
            ->merge($taches->pluck('assignee_id'))
            ->filter()
            ->unique();

        return User::whereIn('id', $identifiants)
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => $this->construire($u, $periode, $affectations, $taches))
            ->filter(fn (ChargeCollaborateur $c) => $c->nombreProjets() > 0)
            ->sortByDesc(fn (ChargeCollaborateur $c) => $c->total())
            ->values();
    }

    /**
     * Charge d'un collaborateur sur une période.
     */
    public function pourCollaborateur(User $user, Periode $periode): ChargeCollaborateur
    {
        return $this->construire($user, $periode, $this->affectations(), $this->taches());
    }

    /**
     * Série de charges d'un collaborateur sur plusieurs périodes consécutives.
     *
     * @param  array<int, Periode>  $periodes
     * @return Collection<int, array{periode: Periode, charge: ChargeCollaborateur}>
     */
    public function serie(User $user, array $periodes): Collection
    {
        $affectations = $this->affectations();
        $taches = $this->taches();

        return collect($periodes)->map(fn (Periode $p) => [
            'periode' => $p,
            'charge' => $this->construire($user, $p, $affectations, $taches),
        ]);
    }

    /**
     * Affectations retenues : celles des projets réellement en cours.
     * Un projet suspendu ne consomme pas de charge.
     *
     * @return Collection<int, ProjectMember>
     */
    private function affectations(): Collection
    {
        return ProjectMember::query()
            ->whereHas('project', fn ($q) => $q->whereIn('statut', [
                ProjectStatus::EnCours->value,
                ProjectStatus::EnPreparation->value,
            ]))
            ->with('project')
            ->get();
    }

    /**
     * Tâches non clôturées des projets en cours, quel que soit leur porteur.
     *
     * @return Collection<int, Task>
     */
    private function taches(): Collection
    {
        return Task::query()
            ->whereNotNull('assignee_id')
            ->whereNotIn('statut', [ProgressStatus::Termine->value, ProgressStatus::Annule->value])
            ->whereHas('project', fn ($q) => $q->whereIn('statut', [
                ProjectStatus::EnCours->value,
                ProjectStatus::EnPreparation->value,
            ]))
            ->with('project')
            ->get();
    }

    /**
     * @param  Collection<int, ProjectMember>  $affectations
     * @param  Collection<int, Task>  $taches
     */
    private function construire(
        User $user,
        Periode $periode,
        Collection $affectations,
        Collection $taches,
    ): ChargeCollaborateur {
        $siennes = $affectations->where('user_id', $user->id);
        $sesTaches = $taches->where('assignee_id', $user->id);

        /** @var Collection<int, ChargeProjet> $lignes */
        $lignes = collect();

        $projets = $siennes->pluck('project')
            ->merge($sesTaches->pluck('project'))
            ->filter()
            ->unique('id');

        foreach ($projets as $projet) {
            $affectation = $siennes->firstWhere('project_id', $projet->id);
            $tachesProjet = $sesTaches->where('project_id', $projet->id);

            $planifiee = $affectation
                ? $this->chargeNominale($affectation, $projet, $periode)
                : 0.0;

            $ouvertes = $tachesProjet->filter(
                fn (Task $t) => $this->tacheDansLaPeriode($t, $periode),
            );

            $assignee = $this->chargeDesTaches($ouvertes, $periode);

            if ($planifiee <= 0 && $assignee <= 0) {
                continue;
            }

            $lignes->push(new ChargeProjet(
                projet: $projet,
                role: $affectation?->role,
                planifiee: round($planifiee, 1),
                assignee: round($assignee, 1),
                tachesOuvertes: $ouvertes->count(),
                // Sans affectation nominale, la charge vient d'une délégation.
                parDelegation: $affectation === null
                    || $affectation->role === MemberRole::Contributeur,
            ));
        }

        return new ChargeCollaborateur($user, $lignes->sortByDesc(fn (ChargeProjet $c) => $c->retenue())->values());
    }

    /**
     * Allocation nominale pondérée par le rôle, au prorata de la période couverte.
     */
    private function chargeNominale(ProjectMember $affectation, Project $projet, Periode $periode): float
    {
        $recouvrement = $periode->recouvrement(
            $affectation->date_debut ?? $projet->date_debut,
            $affectation->date_fin ?? $projet->dateFinEffective(),
        );

        if ($recouvrement <= 0) {
            return 0.0;
        }

        return $affectation->allocation_pct * $affectation->role->poids() / 100 * $recouvrement;
    }

    /**
     * Charge des tâches, en pourcentage d'un temps plein sur la période.
     *
     * @param  Collection<int, Task>  $taches
     */
    private function chargeDesTaches(Collection $taches, Periode $periode): float
    {
        $jours = $taches->sum(fn (Task $t) => $t->charge_jours ?? self::CHARGE_JOURS_PAR_DEFAUT);

        return $jours / $periode->joursOuvres() * 100;
    }

    /**
     * La tâche pèse-t-elle sur cette période ? Une tâche en retard continue de
     * peser sur la période courante : elle reste à faire.
     */
    private function tacheDansLaPeriode(Task $tache, Periode $periode): bool
    {
        $fenetre = $tache->fenetre();

        if (! $fenetre) {
            return false;
        }

        [$debut, $fin] = $fenetre;

        if ($fin->lt($periode->debut)) {
            return $periode->contient(now()) && $tache->enRetard();
        }

        return $debut->lte($periode->fin);
    }

    /**
     * Rattache au projet le collaborateur à qui l'on confie une tâche, s'il n'y
     * était pas déjà : sans cela il ne verrait pas le projet et n'apparaîtrait
     * dans aucun plan de charge.
     */
    public function rattacherParDelegation(Task $tache): ?ProjectMember
    {
        if (! $tache->assignee_id) {
            return null;
        }

        $dejaMembre = ProjectMember::where('project_id', $tache->project_id)
            ->where('user_id', $tache->assignee_id)
            ->exists();

        if ($dejaMembre) {
            return null;
        }

        return ProjectMember::create([
            'project_id' => $tache->project_id,
            'user_id' => $tache->assignee_id,
            'role' => MemberRole::parDelegation()->value,
            // Aucune allocation nominale : la charge est portée par les tâches.
            'allocation_pct' => 1,
            'commentaire' => 'Rattaché automatiquement suite à une tâche confiée.',
        ]);
    }
}
