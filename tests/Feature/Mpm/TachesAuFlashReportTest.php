<?php

namespace Tests\Feature\Mpm;

use App\Enums\FlashItemType;
use App\Enums\ProgressStatus;
use App\Enums\UserRole;
use App\Models\DeliverableType;
use App\Models\FlashReport;
use App\Models\Project;
use App\Models\User;
use App\Services\FlashReportBuilder;
use App\Services\ProjectProvisioner;
use Database\Seeders\MpmReferentialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

/**
 * Les tâches d'un projet, telles qu'elles remplissent son flash report.
 *
 * Le rapport ne recopie pas seulement des libellés : chaque ligne porte le
 * responsable et l'avancement de la tâche, et un mot de statut qui dit dans
 * quel état elle était la semaine rapportée.
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

    private function rapport(): FlashReport
    {
        return app(FlashReportBuilder::class)
            ->preparer($this->project, Date::parse('2026-08-10'))
            ->load('items');
    }

    /**
     * Une tâche en retard appartient au compte rendu de la semaine autant que
     * celles qui ont abouti : la taire donnerait un rapport où seul ce qui a
     * marché figure.
     */
    public function test_les_activites_realisees_reprennent_le_termine_lencours_et_le_retard(): void
    {
        $porteur = User::factory()->create(['name' => 'Katina OUATTARA']);

        $this->project->tasks()->createMany([
            [
                'libelle' => 'Livrer le connecteur',
                'statut' => ProgressStatus::Termine,
                'date_echeance' => '2026-08-12',
                'date_realisation' => '2026-08-12',
                'avancement_pct' => 100,
                'assignee_id' => $porteur->id,
            ],
            [
                'libelle' => 'Homologuer les flux',
                'statut' => ProgressStatus::EnCours,
                'date_echeance' => '2026-08-13',
                'avancement_pct' => 50,
            ],
            [
                'libelle' => 'Recetter le module de paiement',
                'statut' => ProgressStatus::NonDemarre,
                'date_echeance' => '2026-07-20',
                'avancement_pct' => 0,
            ],
        ]);

        $realisees = $this->rapport()->items
            ->where('type', FlashItemType::Realisee)
            ->pluck('statut_libelle', 'libelle');

        $this->assertSame('Terminé', $realisees['Livrer le connecteur']);
        $this->assertSame('En cours', $realisees['Homologuer les flux']);
        $this->assertSame('En retard', $realisees['Recetter le module de paiement']);
    }

    /**
     * Le détail de la tâche accompagne la ligne : au comité, la charge et
     * l'avancement sont les deux premières questions posées.
     */
    public function test_chaque_ligne_porte_le_responsable_et_lavancement(): void
    {
        $porteur = User::factory()->create(['name' => 'Katina OUATTARA']);

        $this->project->tasks()->create([
            'libelle' => 'Homologuer les flux',
            'statut' => ProgressStatus::EnCours,
            'date_echeance' => '2026-08-13',
            'avancement_pct' => 50,
            'assignee_id' => $porteur->id,
        ]);

        $ligne = $this->rapport()->items->firstWhere('libelle', 'Homologuer les flux');

        $this->assertSame('Katina OUATTARA', $ligne->responsable);
        $this->assertSame(50.0, $ligne->avancement_pct);
    }

    /**
     * Les activités à réaliser ne reprennent que ce qui n'a pas commencé :
     * une tâche en cours a déjà été dite dans la rubrique précédente.
     */
    public function test_les_activites_a_realiser_distinguent_a_faire_et_a_planifier(): void
    {
        $this->project->tasks()->createMany([
            [
                'libelle' => 'Ouvrir le chantier de recette',
                'statut' => ProgressStatus::NonDemarre,
                'date_echeance' => '2026-08-19',
            ],
            [
                'libelle' => 'Consolider le besoin métier',
                'statut' => ProgressStatus::NonDemarre,
            ],
            [
                'libelle' => 'Homologuer les flux',
                'statut' => ProgressStatus::EnCours,
                'date_echeance' => '2026-08-13',
            ],
        ]);

        $aRealiser = $this->rapport()->items
            ->where('type', FlashItemType::ARealiser)
            ->pluck('statut_libelle', 'libelle');

        $this->assertSame('À faire', $aRealiser['Ouvrir le chantier de recette']);
        $this->assertSame('À planifier', $aRealiser['Consolider le besoin métier']);
        $this->assertArrayNotHasKey('Homologuer les flux', $aRealiser->all());
    }

    public function test_le_detail_de_la_tache_saffiche_sur_la_diapositive(): void
    {
        $porteur = User::factory()->create(['name' => 'Katina OUATTARA']);

        $this->project->tasks()->create([
            'libelle' => 'Homologuer les flux',
            'statut' => ProgressStatus::EnCours,
            'date_echeance' => '2026-08-13',
            'avancement_pct' => 50,
            'assignee_id' => $porteur->id,
        ]);

        $this->rapport();

        $this->actingAs($this->chef)
            ->get(route('projets.show', $this->project).'?onglet=rapports')
            ->assertOk()
            ->assertSee('Homologuer les flux')
            ->assertSee('Katina OUATTARA')
            ->assertSee('50 %');
    }

    /**
     * Le pré-remplissage n'a lieu qu'à la création : une tâche ajoutée ensuite
     * laissait le rapport vide alors que le projet avançait. Le réalignement
     * la reprend.
     */
    public function test_le_realignement_reprend_les_taches_creees_depuis(): void
    {
        $rapport = $this->rapport();

        $this->assertCount(0, $rapport->items->whereNotNull('task_id'));

        $this->project->tasks()->create([
            'libelle' => 'Ouvrir le chantier de recette',
            'statut' => ProgressStatus::NonDemarre,
            'date_echeance' => '2026-08-19',
        ]);

        $rafraichi = app(FlashReportBuilder::class)->rafraichir($rapport);

        $this->assertSame(
            'À faire',
            $rafraichi->items->firstWhere('libelle', 'Ouvrir le chantier de recette')?->statut_libelle,
        );
    }

    /**
     * Le réalignement rattrape ce que le projet a changé ; il n'efface pas ce
     * que le chef de projet a écrit.
     */
    public function test_le_realignement_ne_touche_pas_aux_lignes_ecrites_a_la_main(): void
    {
        $rapport = $this->rapport();

        $rapport->items()->create([
            'type' => FlashItemType::Attention,
            'libelle' => 'Le client demande une démonstration le 3 septembre',
            'ordre' => 99,
        ]);

        app(FlashReportBuilder::class)->rafraichir($rapport);

        $this->assertNotNull(
            $rapport->refresh()->items->firstWhere('libelle', 'Le client demande une démonstration le 3 septembre'),
        );
    }

    /**
     * Un rapport publié est un engagement rendu : le réalignement n'y touche
     * pas.
     */
    public function test_un_rapport_publie_ne_se_realigne_pas(): void
    {
        $rapport = $this->rapport();
        app(FlashReportBuilder::class)->publier($rapport);

        $this->project->tasks()->create([
            'libelle' => 'Tâche postérieure à la publication',
            'statut' => ProgressStatus::NonDemarre,
            'date_echeance' => '2026-08-19',
        ]);

        app(FlashReportBuilder::class)->rafraichir($rapport);

        $this->assertNull(
            $rapport->refresh()->items->firstWhere('libelle', 'Tâche postérieure à la publication'),
        );
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
