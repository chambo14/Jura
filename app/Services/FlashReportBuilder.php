<?php

namespace App\Services;

use App\Enums\FlashItemType;
use App\Enums\FlashReportStatus;
use App\Enums\ProgressStatus;
use App\Models\FlashReport;
use App\Models\Project;
use App\Models\Task;
use App\Support\Alert;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Prépare le flash report hebdomadaire d'un projet.
 *
 * Convention retenue d'après les rapports existants : le rapport d'une semaine
 * liste en « activités réalisées » les tâches clôturées pendant cette semaine,
 * et en « activités à réaliser » les tâches ouvertes échéant la semaine suivante.
 */
class FlashReportBuilder
{
    public function __construct(
        private readonly ProjectHealthService $health,
    ) {}

    /**
     * Lundi de la semaine contenant la date fournie.
     */
    public static function lundiDe(?CarbonInterface $date = null): CarbonInterface
    {
        return ($date ?? now())->copy()->startOfWeek(CarbonInterface::MONDAY);
    }

    /**
     * Récupère le rapport de la semaine, ou le crée pré-rempli s'il n'existe pas.
     */
    public function preparer(Project $project, ?CarbonInterface $semaine = null, ?int $auteurId = null): FlashReport
    {
        $lundi = self::lundiDe($semaine);
        $vendredi = $lundi->copy()->addDays(4);

        $existant = $project->flashReports()->pourSemaine($lundi)->first();

        if ($existant) {
            return $existant;
        }

        return DB::transaction(function () use ($project, $lundi, $vendredi, $auteurId) {
            $rapport = $project->flashReports()->create([
                'auteur_id' => $auteurId ?? $project->chef_projet_id,
                'semaine_du' => $lundi,
                'semaine_au' => $vendredi,
                'statut' => FlashReportStatus::Brouillon,
                ...$this->instantane($project),
            ]);

            $this->remplirItems($rapport, $project, $lundi, $vendredi);

            return $rapport->load('items');
        });
    }

    /**
     * Recopie l'état courant du projet dans le rapport (chiffres et positions).
     *
     * @return array<string, mixed>
     */
    public function instantane(Project $project): array
    {
        return [
            'date_debut' => $project->date_debut,
            'date_fin_initiale' => $project->date_fin_initiale,
            'date_fin_revisee' => $project->date_fin_revisee,
            'avancement_pct' => $project->avancement_pct,
            'taux_realisation_pct' => $project->taux_realisation_pct,
            'workflow_step_id' => $project->workflow_step_id,
            'phase_id' => $project->phase_id,
            'lien_planning' => $project->lien_planning,
            'sante' => $this->health->diagnostiquer($project)->statut,
        ];
    }

    /**
     * Réaligne un brouillon existant sur l'état courant du projet.
     */
    public function rafraichir(FlashReport $rapport): FlashReport
    {
        if (! $rapport->statut->isEditable()) {
            return $rapport;
        }

        $rapport->update($this->instantane($rapport->project));

        return $rapport->refresh();
    }

    private function remplirItems(FlashReport $rapport, Project $project, CarbonInterface $lundi, CarbonInterface $vendredi): void
    {
        $this->ajouterRealisees($rapport, $project, $lundi, $vendredi);
        $this->ajouterARealiser($rapport, $project, $lundi->copy()->addWeek(), $vendredi->copy()->addWeek());
        $this->ajouterPointsAttention($rapport, $project);
    }

    private function ajouterRealisees(FlashReport $rapport, Project $project, CarbonInterface $du, CarbonInterface $au): void
    {
        $taches = $project->tasks()
            ->realiseesEntre($du, $au)
            ->orderByRaw('COALESCE(date_realisation, date_echeance)')
            ->get();

        foreach ($taches->values() as $index => $tache) {
            $rapport->items()->create([
                'task_id' => $tache->id,
                'type' => FlashItemType::Realisee,
                'libelle' => $tache->libelle,
                'date_ref' => $tache->date_realisation ?? $tache->date_echeance,
                'statut_libelle' => $tache->statut === ProgressStatus::Termine ? 'Terminé' : 'En cours',
                'ordre' => $index + 1,
            ]);
        }
    }

    private function ajouterARealiser(FlashReport $rapport, Project $project, CarbonInterface $du, CarbonInterface $au): void
    {
        // Une tâche déjà citée en « activités réalisées » n'est pas reprise ici.
        $dejaCitees = $rapport->items()->whereNotNull('task_id')->pluck('task_id')->all();

        $taches = $project->tasks()
            ->attenduesEntre($du, $au)
            ->whereNotIn('id', $dejaCitees)
            ->orderBy('date_echeance')
            ->get();

        // Une tâche ouverte sans échéance n'entrait dans aucune fenêtre : elle
        // restait invisible du rapport, semaine après semaine, alors qu'elle
        // est précisément du travail annoncé et non encore fait. Elle passe
        // après les tâches datées, l'ordre du jour restant chronologique.
        $sansEcheance = $project->tasks()
            ->ouvertes()
            ->whereNull('date_echeance')
            ->whereNotIn('id', $dejaCitees)
            ->whereNotIn('id', $taches->pluck('id')->all())
            ->orderBy('ordre')
            ->orderBy('libelle')
            ->get();

        foreach ($taches->concat($sansEcheance)->values() as $index => $tache) {
            $rapport->items()->create([
                'task_id' => $tache->id,
                'type' => FlashItemType::ARealiser,
                'libelle' => $tache->libelle,
                'date_ref' => $tache->date_echeance,
                'statut_libelle' => $tache->enRetard() ? 'En retard' : null,
                'ordre' => $index + 1,
            ]);
        }
    }

    private function ajouterPointsAttention(FlashReport $rapport, Project $project): void
    {
        $project->loadMissing(['milestones', 'tasks', 'deliverables']);

        foreach ($this->health->diagnostiquer($project)->alertes->values() as $index => $alerte) {
            /** @var Alert $alerte */
            $rapport->items()->create([
                'type' => FlashItemType::Attention,
                'libelle' => $alerte->texteComplet(),
                'ordre' => $index + 1,
            ]);
        }
    }

    /**
     * Publie le rapport : fige les chiffres et le rend visible au comité.
     */
    public function publier(FlashReport $rapport): FlashReport
    {
        $rapport->update([
            ...$this->instantane($rapport->project),
            'statut' => FlashReportStatus::Publie,
            'publie_at' => now(),
        ]);

        return $rapport->refresh();
    }

    /**
     * Ajoute une ligne libre dans une rubrique, à la suite des lignes existantes.
     */
    public function ajouterLigne(FlashReport $rapport, FlashItemType $type, string $libelle, ?CarbonInterface $date = null): void
    {
        $rapport->items()->create([
            'type' => $type,
            'libelle' => $libelle,
            'date_ref' => $date,
            'ordre' => ($rapport->items()->where('type', $type->value)->max('ordre') ?? 0) + 1,
        ]);
    }

    /**
     * Tâches candidates non encore reprises dans le rapport, pour proposition au chef de projet.
     *
     * @return Collection<int, Task>
     */
    public function tachesNonReprises(FlashReport $rapport): Collection
    {
        $reprises = $rapport->items()->whereNotNull('task_id')->pluck('task_id')->all();

        return $rapport->project->tasks()
            ->whereNotIn('id', $reprises)
            ->orderBy('date_echeance')
            ->get();
    }
}
