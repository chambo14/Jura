<?php

namespace App\Services;

use App\Enums\DeliverableStatus;
use App\Enums\MemberRole;
use App\Enums\ProgressStatus;
use App\Models\DeliverableType;
use App\Models\Phase;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\ProjectStep;
use App\Models\WorkflowStep;
use App\Support\Referentiel\Perimetre;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Déroule le référentiel MPM sur un projet : instanciation des 8 phases,
 * du bandeau d'avancement propre au type de projet et des livrables attendus.
 */
class ProjectProvisioner
{
    /**
     * Applique le référentiel à un projet, dans le périmètre retenu pour lui.
     *
     * Sans périmètre, le référentiel s'applique en entier : c'est ce
     * qu'attendent le jeu de démonstration et la resynchronisation.
     */
    public function provisionner(Project $project, ?Perimetre $perimetre = null): void
    {
        $perimetre ??= Perimetre::complet();

        DB::transaction(function () use ($project, $perimetre) {
            $this->instancierPhases($project, $perimetre);
            $this->instancierEtapes($project, $perimetre);
            $this->instancierLivrables($project, $perimetre);
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
     * Phases écartées que le projet conserve malgré tout.
     *
     * @param  array<int, int>  $phasesRetenues
     * @return Collection<int, ProjectPhase>
     */
    public function phasesConservees(Project $project, array $phasesRetenues): Collection
    {
        return $project->phases()
            ->whereNotIn('phase_id', $phasesRetenues ?: [0])
            ->with('phase')
            ->get();
    }

    /**
     * Recrée les livrables manquants sans toucher à ceux déjà renseignés.
     * Utile après une mise à jour du référentiel MPM.
     */
    public function resynchroniserLivrables(Project $project, ?Perimetre $perimetre = null): int
    {
        $existants = $project->deliverables()
            ->whereNotNull('deliverable_type_id')
            ->pluck('deliverable_type_id')
            ->all();

        $ajoutes = 0;

        foreach ($this->livrablesApplicables($project, $perimetre ?? Perimetre::complet()) as $type) {
            if (in_array($type->id, $existants, true)) {
                continue;
            }

            $this->creerLivrable($project, $type);
            $ajoutes++;
        }

        return $ajoutes;
    }

    private function instancierPhases(Project $project, Perimetre $perimetre): void
    {
        $phases = Phase::ordered()->get();

        if ($perimetre->restreintLesPhases()) {
            $this->retirerLesPhasesEcartees($project, $perimetre);
            $phases = $phases->whereIn('id', $perimetre->phasesRetenues())->values();
        }

        foreach ($phases as $phase) {
            $project->phases()->firstOrCreate(
                ['phase_id' => $phase->id],
                ['ordre' => $phase->ordre, 'statut' => ProgressStatus::NonDemarre],
            );
        }

        // La phase courante doit rester une phase du projet.
        $courante = $project->phase_id;

        if ($phases->isNotEmpty() && $courante && ! $phases->contains('id', $courante)) {
            $project->phase_id = $phases->first()->id;
            $project->save();
        }
    }

    /**
     * Retire les phases écartées — sauf celles où quelque chose est consigné.
     *
     * Une phase datée, démarrée, avancée ou commentée témoigne d'un travail
     * réel, et ses livrables déjà produits en dépendent. L'écarter effacerait
     * cette trace : elle reste, et l'écran dit pourquoi.
     */
    private function retirerLesPhasesEcartees(Project $project, Perimetre $perimetre): void
    {
        $retenues = $perimetre->phasesRetenues();

        $vierges = $project->phases()
            ->whereNotIn('phase_id', $retenues)
            ->where('statut', ProgressStatus::NonDemarre->value)
            ->where('avancement_pct', 0)
            ->whereNull('date_debut_reelle')
            ->whereNull('date_fin_reelle')
            ->whereNull('commentaire')
            ->pluck('phase_id');

        if ($vierges->isEmpty()) {
            return;
        }

        // Un livrable déjà travaillé retient sa phase : le retirer perdrait la
        // pièce elle-même, et ce n'est pas ce qu'on a demandé en décochant.
        $retenuesParUnLivrable = $project->deliverables()
            ->whereIn('phase_id', $vierges)
            ->where(fn ($q) => $q->where('statut', '!=', DeliverableStatus::AProduire->value)
                ->orWhereNotNull('date_livraison')
                ->orWhereNotNull('fichier_path')
                ->orWhereNotNull('lien'))
            ->pluck('phase_id')
            ->unique();

        $retirables = $vierges->diff($retenuesParUnLivrable);

        if ($retirables->isEmpty()) {
            return;
        }

        $project->deliverables()->whereIn('phase_id', $retirables)->delete();
        $project->phases()->whereIn('phase_id', $retirables)->delete();
    }

    private function instancierEtapes(Project $project, Perimetre $perimetre): void
    {
        $etapes = WorkflowStep::forType($project->type_projet)->get();

        if ($perimetre->restreintLesEtapes()) {
            $this->retirerLesEtapesEcartees($project, $perimetre->etapesRetenues());
            $etapes = $etapes->whereIn('id', $perimetre->etapesRetenues())->values();
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

    private function instancierLivrables(Project $project, Perimetre $perimetre): void
    {
        // Passe par la resynchronisation pour rester rejouable sur un projet déjà provisionné.
        $this->resynchroniserLivrables($project, $perimetre);
    }

    /**
     * @return Collection<int, DeliverableType>
     */
    private function livrablesApplicables(Project $project, Perimetre $perimetre): Collection
    {
        return DeliverableType::with('phase')
            ->when(
                $perimetre->restreintLesPhases(),
                fn ($q) => $q->whereIn('phase_id', $perimetre->phasesRetenues()),
            )
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
