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
use Illuminate\Database\Eloquent\Builder;
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
     *
     * Le pré-remplissage n'a lieu qu'à la création : une tâche ajoutée ensuite
     * n'entrait dans aucune rubrique, et le rapport restait vide alors que le
     * projet, lui, avançait. Le réalignement reprend donc aussi les activités.
     *
     * Il ne touche qu'aux lignes issues des tâches. Les points d'attention et
     * tout ce que le chef de projet a écrit de sa main sont conservés : c'est
     * son rapport, le réalignement ne fait que rattraper ce que le projet a
     * changé depuis.
     */
    public function rafraichir(FlashReport $rapport): FlashReport
    {
        if (! $rapport->statut->isEditable()) {
            return $rapport;
        }

        return DB::transaction(function () use ($rapport) {
            $rapport->update($this->instantane($rapport->project));

            $rapport->items()->whereNotNull('task_id')->delete();
            $rapport->load('items');

            $lundi = $rapport->semaine_du->copy();
            $vendredi = $rapport->semaine_au->copy();

            $this->ajouterRealisees($rapport, $rapport->project, $lundi, $vendredi);
            $this->ajouterARealiser(
                $rapport,
                $rapport->project,
                $lundi->copy()->addWeek(),
                $vendredi->copy()->addWeek(),
            );

            return $rapport->refresh()->load('items');
        });
    }

    private function remplirItems(FlashReport $rapport, Project $project, CarbonInterface $lundi, CarbonInterface $vendredi): void
    {
        $this->ajouterRealisees($rapport, $project, $lundi, $vendredi);
        $this->ajouterARealiser($rapport, $project, $lundi->copy()->addWeek(), $vendredi->copy()->addWeek());
        $this->ajouterPointsAttention($rapport, $project);
    }

    /**
     * « Activités réalisées la semaine antérieure » : ce que la semaine devait
     * produire, et ce qu'elle en a fait.
     *
     * Trois cas y ont leur place — terminé, en cours, en retard. Le troisième
     * est le plus important : une tâche qui devait être faite et ne l'est pas
     * appartient au compte rendu de la semaine autant que celles qui ont
     * abouti. La taire donnerait un rapport où seul ce qui a marché figure.
     */
    private function ajouterRealisees(FlashReport $rapport, Project $project, CarbonInterface $du, CarbonInterface $au): void
    {
        $debut = $du->toDateString();
        $fin = $au->toDateString();

        $taches = $project->tasks()
            ->with('assignee')
            ->where(function (Builder $q) use ($debut, $fin) {
                // Ce que la semaine a produit.
                $q->where(function (Builder $close) use ($debut, $fin) {
                    $close->where('statut', ProgressStatus::Termine->value)
                        ->whereDate('date_realisation', '>=', $debut)
                        ->whereDate('date_realisation', '<=', $fin);
                })
                    // Tout ce qui est engagé se raconte, quelle que soit son
                    // échéance : c'est le travail en cours du projet, et le
                    // borner à une fenêtre le faisait disparaître du rapport.
                    ->orWhere('statut', ProgressStatus::EnCours->value)
                    ->orWhere(function (Builder $retard) use ($debut) {
                        // Ouverte alors que son échéance était antérieure à la
                        // semaine : elle aurait dû être faite, elle est en
                        // retard. Une tâche dont l'échéance tombe dans la
                        // semaine ne l'est pas — la semaine était la sienne.
                        $retard->ouvertes()->whereDate('date_echeance', '<', $debut);
                    });
            })
            ->orderByRaw('COALESCE(date_realisation, date_echeance)')
            ->get();

        foreach ($taches->values() as $index => $tache) {
            $rapport->items()->create([
                'task_id' => $tache->id,
                'type' => FlashItemType::Realisee,
                'libelle' => $tache->libelle,
                'responsable' => $tache->assignee?->name,
                'avancement_pct' => $tache->avancement_pct,
                'date_ref' => $tache->date_realisation ?? $tache->date_echeance,
                'statut_libelle' => $this->statutRealisee($tache, $du),
                'ordre' => $index + 1,
            ]);
        }
    }

    /**
     * Le retard se juge par rapport à la semaine rapportée, et non au jour où
     * l'on rédige : un rapport préparé pour une semaine passée doit dire ce qui
     * était vrai alors.
     *
     * Une tâche encore ouverte dont l'échéance tombait **avant** la semaine
     * est en retard : elle aurait dû être faite. Une tâche dont l'échéance
     * tombe dans la semaine reste en cours — la semaine était la sienne.
     */
    private function statutRealisee(Task $tache, CarbonInterface $du): string
    {
        if ($tache->statut === ProgressStatus::Termine) {
            return 'Terminé';
        }

        $enRetard = $tache->date_echeance !== null
            && $tache->date_echeance->lessThan($du)
            && ! $tache->statut->isClosed();

        return $enRetard ? 'En retard' : 'En cours';
    }

    /**
     * « Activités à réaliser cette semaine » : le travail annoncé, pas celui
     * qui est déjà engagé.
     *
     * Une tâche en cours ou en retard a été dite dans la rubrique précédente ;
     * ne restent ici que celles qui n'ont pas commencé. Deux cas : elle a une
     * échéance, c'est « À faire » ; elle n'en a pas, c'est « À planifier » —
     * du travail reconnu dont la date reste à poser. Sans ce second cas, une
     * tâche sans échéance n'entrait dans aucune fenêtre et restait invisible
     * du rapport, semaine après semaine.
     */
    private function ajouterARealiser(FlashReport $rapport, Project $project, CarbonInterface $du, CarbonInterface $au): void
    {
        // Une tâche déjà citée en « activités réalisées » n'est pas reprise ici.
        $dejaCitees = $rapport->items()->whereNotNull('task_id')->pluck('task_id')->all();
        $fin = $au->toDateString();

        $taches = $project->tasks()
            ->with('assignee')
            ->whereIn('statut', [
                ProgressStatus::NonDemarre->value,
                ProgressStatus::Bloque->value,
            ])
            ->whereNotIn('id', $dejaCitees)
            ->where(function (Builder $q) use ($fin) {
                $q->whereNull('date_echeance')
                    ->orWhereDate('date_echeance', '<=', $fin);
            })
            // Les tâches datées d'abord, dans l'ordre du calendrier ; celles
            // qui restent à planifier ferment la liste.
            ->orderByRaw('CASE WHEN date_echeance IS NULL THEN 1 ELSE 0 END')
            ->orderBy('date_echeance')
            ->orderBy('ordre')
            ->orderBy('libelle')
            ->get();

        foreach ($taches->values() as $index => $tache) {
            $rapport->items()->create([
                'task_id' => $tache->id,
                'type' => FlashItemType::ARealiser,
                'libelle' => $tache->libelle,
                'responsable' => $tache->assignee?->name,
                'avancement_pct' => $tache->avancement_pct,
                'date_ref' => $tache->date_echeance,
                'statut_libelle' => $this->statutARealiser($tache),
                'ordre' => $index + 1,
            ]);
        }
    }

    /**
     * Une tâche sans échéance n'est pas « à faire cette semaine » : elle est
     * reconnue mais pas encore posée dans le calendrier. Le dire évite de la
     * compter comme un engagement de la semaine.
     */
    private function statutARealiser(Task $tache): string
    {
        return match (true) {
            $tache->date_echeance === null => 'À planifier',
            $tache->statut === ProgressStatus::Bloque => 'Bloqué',
            default => 'À faire',
        };
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
