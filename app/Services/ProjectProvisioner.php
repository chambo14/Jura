<?php

namespace App\Services;

use App\Enums\DeliverableStatus;
use App\Enums\MemberRole;
use App\Enums\ProgressStatus;
use App\Models\DeliverableType;
use App\Models\Phase;
use App\Models\Project;
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
     * Applique le référentiel à un projet fraîchement créé.
     */
    public function provisionner(Project $project): void
    {
        DB::transaction(function () use ($project) {
            $this->instancierPhases($project);
            $this->instancierEtapes($project);
            $this->instancierLivrables($project);
            $this->synchroniserPilotage($project);
        });
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

    private function instancierEtapes(Project $project): void
    {
        $etapes = WorkflowStep::forType($project->type_projet)->get();

        foreach ($etapes as $etape) {
            $project->steps()->firstOrCreate(
                ['workflow_step_id' => $etape->id],
                ['ordre' => $etape->ordre, 'statut' => ProgressStatus::NonDemarre],
            );
        }

        if (! $project->workflow_step_id && $etapes->isNotEmpty()) {
            $project->workflow_step_id = $etapes->first()->id;
            $project->save();
        }
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
