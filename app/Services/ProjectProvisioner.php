<?php

namespace App\Services;

use App\Enums\DeliverableStatus;
use App\Enums\MemberRole;
use App\Enums\ProgressStatus;
use App\Models\DeliverableType;
use App\Models\Phase;
use App\Models\Project;
use App\Models\ProjectStep;
use App\Models\WorkflowStep;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Déroule le référentiel MPM sur un projet : instanciation des 8 phases,
 * du bandeau d'avancement propre au type de projet et des livrables attendus.
 */
class ProjectProvisioner
{
    /**
     * Applique le référentiel à un projet.
     *
     * `$etapesRetenues` restreint le bandeau d'avancement aux étapes choisies
     * pour ce projet — tous les projets ne passent pas par le pilote ni la
     * généralisation. Sans liste, le type de projet donne l'intégralité de
     * ses étapes, ce qui reste le comportement des appels existants.
     *
     * @param  array<int, int>|null  $etapesRetenues  identifiants de WorkflowStep
     */
    public function provisionner(Project $project, ?array $etapesRetenues = null): void
    {
        DB::transaction(function () use ($project, $etapesRetenues) {
            $this->instancierPhases($project);
            $this->instancierEtapes($project, $etapesRetenues);
            $this->instancierLivrables($project);
            $this->synchroniserPilotage($project);
        });
    }

    /**
     * Étapes écartées que le projet conserve malgré tout, parce qu'elles
     * portent une trace de travail.
     *
     * @param  array<int, int>  $etapesRetenues
     * @return Collection<int, ProjectStep>
     */
    public function etapesConservees(Project $project, array $etapesRetenues): Collection
    {
        return $project->steps()
            ->whereNotIn('workflow_step_id', $etapesRetenues ?: [0])
            ->with('workflowStep')
            ->get();
    }

    /**
     * Recrée les livrables manquants sans toucher à ceux déjà renseignés.
     * Utile après une mise à jour du référentiel MPM.
     */
    public function resynchroniserLivrables(Project $project): int
    {
        $existants = $project->deliverables()
            ->whereNotNull('deliverable_type_id')
            ->pluck('deliverable_type_id')
            ->all();

        $ajoutes = 0;

        foreach ($this->livrablesApplicables($project) as $type) {
            if (in_array($type->id, $existants, true)) {
                continue;
            }

            $this->creerLivrable($project, $type);
            $ajoutes++;
        }

        return $ajoutes;
    }

    private function instancierPhases(Project $project): void
    {
        foreach (Phase::ordered()->get() as $phase) {
            $project->phases()->firstOrCreate(
                ['phase_id' => $phase->id],
                ['ordre' => $phase->ordre, 'statut' => ProgressStatus::NonDemarre],
            );
        }
    }

    /**
     * @param  array<int, int>|null  $retenues
     */
    private function instancierEtapes(Project $project, ?array $retenues = null): void
    {
        $etapes = WorkflowStep::forType($project->type_projet)->get();

        if ($retenues !== null) {
            $this->retirerLesEtapesEcartees($project, $retenues);
            $etapes = $etapes->whereIn('id', $retenues)->values();
        }

        foreach ($etapes as $etape) {
            $project->steps()->firstOrCreate(
                ['workflow_step_id' => $etape->id],
                ['ordre' => $etape->ordre, 'statut' => ProgressStatus::NonDemarre],
            );
        }

        // L'étape courante doit rester une étape du projet : écarter celle sur
        // laquelle il se trouvait laisserait le bandeau pointer dans le vide.
        $courante = $project->workflow_step_id;

        if ($etapes->isNotEmpty() && (! $courante || ! $etapes->contains('id', $courante))) {
            $project->workflow_step_id = $etapes->first()->id;
            $project->save();
        }
    }

    /**
     * Retire les étapes décochées — sauf celles où quelque chose est consigné.
     *
     * Une étape démarrée, datée ou annotée témoigne d'un travail réel. La
     * décocher effacerait cette trace sans que personne l'ait demandé : elle
     * reste, et l'écran dit pourquoi.
     *
     * @param  array<int, int>  $retenues
     */
    private function retirerLesEtapesEcartees(Project $project, array $retenues): void
    {
        $project->steps()
            ->whereNotIn('workflow_step_id', $retenues ?: [0])
            ->where('statut', ProgressStatus::NonDemarre->value)
            ->whereNull('date_reelle')
            ->whereNull('annotation')
            ->delete();
    }

    private function instancierLivrables(Project $project): void
    {
        // Passe par la resynchronisation pour rester rejouable sur un projet déjà provisionné.
        $this->resynchroniserLivrables($project);
    }

    /**
     * @return Collection<int, DeliverableType>
     */
    private function livrablesApplicables(Project $project): Collection
    {
        return DeliverableType::with('phase')
            ->get()
            ->filter(fn (DeliverableType $type) => $type->appliesTo($project->type_projet))
            ->sortBy([
                fn (DeliverableType $a, DeliverableType $b) => $a->phase->ordre <=> $b->phase->ordre,
                fn (DeliverableType $a, DeliverableType $b) => $a->ordre <=> $b->ordre,
            ])
            ->values();
    }

    private function creerLivrable(Project $project, DeliverableType $type): void
    {
        $project->deliverables()->create([
            'phase_id' => $type->phase_id,
            'deliverable_type_id' => $type->id,
            'nom' => $type->nom,
            'obligatoire' => $type->obligatoire,
            'statut' => DeliverableStatus::AProduire,
            'ordre' => $type->ordre,
        ]);
    }

    /**
     * Reporte les acteurs de l'en-tête "PILOTAGE" dans la table des ressources,
     * pour qu'ils apparaissent aussi dans le plan de charge.
     */
    public function synchroniserPilotage(Project $project): void
    {
        $mapping = [
            MemberRole::ChefProjet->value => $project->chef_projet_id,
            MemberRole::BackUp->value => $project->back_up_id,
            MemberRole::Sponsor->value => $project->sponsor_id,
            MemberRole::ReferentTechnique->value => $project->referent_technique_id,
        ];

        foreach ($mapping as $role => $userId) {
            $project->members()->where('role', $role)->when(
                $userId,
                fn ($query) => $query->where('user_id', '!=', $userId),
            )->delete();

            if ($userId) {
                $project->members()->firstOrCreate(
                    ['user_id' => $userId, 'role' => $role],
                    ['allocation_pct' => 100],
                );
            }
        }
    }
}
