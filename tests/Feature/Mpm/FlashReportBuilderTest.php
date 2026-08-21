<?php

namespace Tests\Feature\Mpm;

use App\Enums\FlashItemType;
use App\Enums\FlashReportStatus;
use App\Enums\ProgressStatus;
use App\Models\Project;
use App\Services\FlashReportBuilder;
use App\Services\ProjectProvisioner;
use Database\Seeders\MpmReferentialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class FlashReportBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MpmReferentialSeeder::class);
    }

    private function projetAvecTaches(): Project
    {
        $project = Project::factory()->create([
            'date_debut' => Date::parse('2026-01-05'),
            'date_fin_initiale' => Date::parse('2026-12-31'),
            'avancement_pct' => 40,
            'taux_realisation_pct' => 38,
        ]);

        app(ProjectProvisioner::class)->provisionner($project);

        // Semaine du rapport : 10 au 14 août 2026.
        $project->tasks()->createMany([
            [
                'libelle' => 'Transmettre le compte rendu',
                'date_echeance' => '2026-08-10',
                'date_realisation' => '2026-08-10',
                'statut' => ProgressStatus::Termine,
            ],
            [
                'libelle' => 'Réaliser les tests sécuritaires',
                'date_echeance' => '2026-08-12',
                'statut' => ProgressStatus::EnCours,
            ],
            // Semaine suivante : 17 au 21 août 2026.
            [
                'libelle' => 'Mettre à jour les CGU',
                'date_echeance' => '2026-08-17',
                'statut' => ProgressStatus::NonDemarre,
            ],
            [
                'libelle' => 'Obtenir le PV de recette provisoire',
                'date_echeance' => '2026-08-21',
                'statut' => ProgressStatus::NonDemarre,
            ],
            // Hors fenêtre : ne doit apparaître nulle part.
            [
                'libelle' => 'Clôturer le projet',
                'date_echeance' => '2026-12-15',
                'statut' => ProgressStatus::NonDemarre,
            ],
        ]);

        return $project->fresh(['tasks', 'milestones', 'deliverables']);
    }

    public function test_une_activite_du_dernier_jour_de_la_semaine_est_reprise(): void
    {
        $project = $this->projetAvecTaches();

        // Vendredi 14/08, dernier jour de la fenêtre : les colonnes date portant une
        // heure, une comparaison de chaînes l'exclurait à tort.
        $project->tasks()->create([
            'libelle' => 'Tenir le COPIL du vendredi',
            'date_echeance' => '2026-08-14',
            'date_realisation' => '2026-08-14',
            'statut' => ProgressStatus::Termine,
        ]);

        $rapport = app(FlashReportBuilder::class)->preparer(
            $project->fresh(['tasks', 'milestones', 'deliverables']),
            Date::parse('2026-08-10'),
        );

        $this->assertTrue(
            $rapport->itemsOfType(FlashItemType::Realisee)->pluck('libelle')->contains('Tenir le COPIL du vendredi'),
        );
    }

    public function test_il_prerempli_les_activites_realisees_de_la_semaine(): void
    {
        $rapport = app(FlashReportBuilder::class)->preparer(
            $this->projetAvecTaches(),
            Date::parse('2026-08-12'),
        );

        $realisees = $rapport->itemsOfType(FlashItemType::Realisee)->pluck('libelle');

        $this->assertCount(2, $realisees);
        $this->assertTrue($realisees->contains('Transmettre le compte rendu'));
        $this->assertTrue($realisees->contains('Réaliser les tests sécuritaires'));
    }

    public function test_il_prerempli_les_activites_de_la_semaine_suivante(): void
    {
        $rapport = app(FlashReportBuilder::class)->preparer(
            $this->projetAvecTaches(),
            Date::parse('2026-08-10'),
        );

        $aRealiser = $rapport->itemsOfType(FlashItemType::ARealiser)->pluck('libelle');

        $this->assertTrue($aRealiser->contains('Mettre à jour les CGU'));
        $this->assertTrue($aRealiser->contains('Obtenir le PV de recette provisoire'));
        $this->assertFalse($aRealiser->contains('Clôturer le projet'));
    }

    public function test_une_tache_deja_citee_en_realisee_nest_pas_repetee(): void
    {
        $rapport = app(FlashReportBuilder::class)->preparer(
            $this->projetAvecTaches(),
            Date::parse('2026-08-10'),
        );

        $realisees = $rapport->itemsOfType(FlashItemType::Realisee)->pluck('task_id');
        $aRealiser = $rapport->itemsOfType(FlashItemType::ARealiser)->pluck('task_id');

        $this->assertTrue($realisees->intersect($aRealiser)->isEmpty());
    }

    public function test_la_semaine_est_toujours_cadree_du_lundi_au_vendredi(): void
    {
        $rapport = app(FlashReportBuilder::class)->preparer(
            $this->projetAvecTaches(),
            Date::parse('2026-08-13'), // un jeudi
        );

        $this->assertSame('2026-08-10', $rapport->semaine_du->toDateString());
        $this->assertSame('2026-08-14', $rapport->semaine_au->toDateString());
        $this->assertSame('DU 10/08/2026 AU 14/08/2026', $rapport->periodeLabel());
    }

    public function test_les_points_dattention_reprennent_les_derives_detectees(): void
    {
        $project = Project::factory()->enDerive()->create();
        app(ProjectProvisioner::class)->provisionner($project);

        $rapport = app(FlashReportBuilder::class)->preparer($project->fresh());

        $this->assertGreaterThan(0, $rapport->itemsOfType(FlashItemType::Attention)->count());
    }

    public function test_un_seul_rapport_par_projet_et_par_semaine(): void
    {
        $project = $this->projetAvecTaches();
        $builder = app(FlashReportBuilder::class);

        $premier = $builder->preparer($project, Date::parse('2026-08-10'));
        $second = $builder->preparer($project->fresh(), Date::parse('2026-08-14'));

        $this->assertSame($premier->id, $second->id);
        $this->assertSame(1, $project->flashReports()->count());
    }

    public function test_la_publication_fige_les_chiffres_du_projet(): void
    {
        $project = $this->projetAvecTaches();
        $builder = app(FlashReportBuilder::class);

        $rapport = $builder->preparer($project, Date::parse('2026-08-10'));

        $project->update(['avancement_pct' => 75]);

        $publie = $builder->publier($rapport->fresh());

        $this->assertSame(FlashReportStatus::Publie, $publie->statut);
        $this->assertNotNull($publie->publie_at);
        $this->assertSame(75.0, $publie->avancement_pct);
        $this->assertFalse($publie->statut->isEditable());
    }

    public function test_un_rapport_publie_nest_plus_rafraichi(): void
    {
        $project = $this->projetAvecTaches();
        $builder = app(FlashReportBuilder::class);

        $rapport = $builder->publier($builder->preparer($project, Date::parse('2026-08-10')));

        $project->update(['avancement_pct' => 99]);

        $this->assertSame(
            $rapport->avancement_pct,
            $builder->rafraichir($rapport->fresh())->avancement_pct,
        );
    }
}
