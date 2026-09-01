<?php

namespace Tests\Feature\Mpm;

use App\Enums\ProgressStatus;
use App\Enums\UserRole;
use App\Models\DeliverableType;
use App\Models\Project;
use App\Models\User;
use App\Services\FlashReportBuilder;
use App\Services\ProjectProvisioner;
use Database\Seeders\MpmReferentialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * L'onglet Flash reports d'un projet montre les tâches telles qu'elles sont
 * à l'instant où on le regarde.
 *
 * Le rapport, lui, est figé au moment où on le prépare : une tâche créée
 * ensuite ne s'y ajoute pas. Sans ce tableau, elle n'apparaissait nulle part
 * dans l'onglet, et rien ne disait pourquoi.
 */
class TachesAuFlashReportTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $chef;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MpmReferentialSeeder::class);

        $this->chef = User::factory()->role(UserRole::ChefProjet)->create();
        $this->project = Project::factory()->create([
            'chef_projet_id' => $this->chef->id,
            'date_debut' => Date::parse('2026-01-05'),
            'date_fin_initiale' => Date::parse('2026-12-31'),
        ]);

        app(ProjectProvisioner::class)->provisionner($this->project);
    }

    private function onglet(): TestResponse
    {
        return $this->actingAs($this->chef)
            ->get(route('projets.show', $this->project).'?onglet=rapports');
    }

    public function test_une_tache_creee_apparait_avec_son_statut_et_son_avancement(): void
    {
        $this->project->tasks()->create([
            'libelle' => 'Rédiger le dossier de cadrage',
            'statut' => ProgressStatus::EnCours,
            'avancement_pct' => 40,
        ]);

        $this->onglet()
            ->assertOk()
            ->assertSee('Rédiger le dossier de cadrage')
            ->assertSee('En cours')
            ->assertSee('40,0 %');
    }

    /**
     * Une tâche créée après la rédaction du rapport ne peut pas y figurer :
     * le tableau le dit, plutôt que de laisser croire qu'elle a été rapportée.
     */
    public function test_une_tache_creee_apres_le_rapport_est_signalee_comme_non_reprise(): void
    {
        app(FlashReportBuilder::class)->preparer($this->project, Date::parse('2026-08-10'));

        $this->project->tasks()->create([
            'libelle' => 'Ouvrir le chantier de recette',
            'statut' => ProgressStatus::NonDemarre,
        ]);

        $this->onglet()
            ->assertOk()
            ->assertSee('Ouvrir le chantier de recette')
            ->assertSee('Non reprise dans le dernier rapport');
    }

    public function test_une_tache_sans_echeance_le_dit_plutot_que_de_laisser_la_case_vide(): void
    {
        $this->project->tasks()->create([
            'libelle' => 'Consolider le besoin métier',
            'statut' => ProgressStatus::NonDemarre,
        ]);

        $this->onglet()->assertOk()->assertSee('Sans échéance');
    }

    /**
     * La maison s'appelle MEDIASOFT LAFAYETTE ; le référentiel des livrables
     * désignait encore MEDIALOGIC comme direction responsable.
     */
    public function test_le_referentiel_designe_mediasoft_lafayette(): void
    {
        $this->assertSame(
            0,
            DeliverableType::query()->where('responsable', 'like', '%MEDIALOGIC%')->count(),
        );

        $this->assertGreaterThan(
            0,
            DeliverableType::query()->where('responsable', 'Direction MEDIASOFT LAFAYETTE')->count(),
        );
    }
}
