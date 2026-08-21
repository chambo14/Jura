<?php

namespace Tests\Feature\Mpm;

use App\Enums\DeliverableStatus;
use App\Enums\HealthStatus;
use App\Enums\ProgressStatus;
use App\Enums\ProjectStatus;
use App\Models\Phase;
use App\Models\Project;
use App\Services\ProjectHealthService;
use App\Support\ProjectHealth;
use Database\Seeders\MpmReferentialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MpmReferentialSeeder::class);
    }

    private function diagnostiquer(Project $project): ProjectHealth
    {
        return app(ProjectHealthService::class)->diagnostiquer(
            $project->load(['milestones', 'tasks', 'deliverables']),
        );
    }

    public function test_un_projet_dans_les_temps_est_vert(): void
    {
        $project = Project::factory()->create([
            'date_debut' => now()->subMonths(3),
            'date_fin_initiale' => now()->addMonths(3),
            'date_fin_revisee' => null,
            'avancement_pct' => 55,
            'taux_realisation_pct' => 55,
        ]);

        $sante = $this->diagnostiquer($project);

        $this->assertSame(HealthStatus::Vert, $sante->statut);
        $this->assertTrue($sante->alertes->isEmpty());
    }

    public function test_un_projet_cloture_nest_pas_diagnostique(): void
    {
        $project = Project::factory()->enDerive()->create([
            'statut' => ProjectStatus::Cloture,
        ]);

        $sante = $this->diagnostiquer($project);

        $this->assertSame(HealthStatus::Vert, $sante->statut);
        $this->assertTrue($sante->alertes->isEmpty());
    }

    public function test_un_glissement_important_declenche_une_alerte_rouge(): void
    {
        $project = Project::factory()->create([
            'date_debut' => now()->subMonths(12),
            'date_fin_initiale' => now()->subMonths(3),
            'date_fin_revisee' => now()->addMonths(3),
        ]);

        $sante = $this->diagnostiquer($project);

        $this->assertSame(HealthStatus::Rouge, $sante->statut);
        $this->assertTrue($sante->alertes->contains(fn ($a) => $a->code === 'glissement'));
        $this->assertGreaterThan(45, $sante->glissementJours);
    }

    public function test_une_echeance_depassee_declenche_une_alerte_rouge(): void
    {
        $project = Project::factory()->create([
            'date_debut' => now()->subMonths(6),
            'date_fin_initiale' => now()->subDays(10),
            'date_fin_revisee' => null,
            'avancement_pct' => 100,
            'taux_realisation_pct' => 100,
        ]);

        $sante = $this->diagnostiquer($project);

        $this->assertTrue($sante->alertes->contains(fn ($a) => $a->code === 'echeance_depassee'));
        $this->assertSame(HealthStatus::Rouge, $sante->statut);
    }

    public function test_un_avancement_en_dessous_du_calendrier_est_signale(): void
    {
        // À mi-parcours, un avancement de 10 % accuse un retard de ~40 points.
        $project = Project::factory()->create([
            'date_debut' => now()->subMonths(6),
            'date_fin_initiale' => now()->addMonths(6),
            'date_fin_revisee' => null,
            'avancement_pct' => 10,
            'taux_realisation_pct' => 10,
        ]);

        $sante = $this->diagnostiquer($project);

        $this->assertNotNull($sante->avancementTheoriquePct);
        $this->assertTrue($sante->enRetardCalendaire());
        $this->assertTrue($sante->alertes->contains(fn ($a) => $a->code === 'avancement'));
    }

    public function test_un_jalon_critique_en_retard_passe_le_projet_en_rouge(): void
    {
        $project = Project::factory()->create([
            'date_debut' => now()->subMonths(3),
            'date_fin_initiale' => now()->addMonths(3),
            'avancement_pct' => 50,
            'taux_realisation_pct' => 50,
        ]);

        $project->milestones()->create([
            'libelle' => 'PV de recette provisoire',
            'date_prevue' => now()->subDays(5),
            'statut' => ProgressStatus::EnCours,
            'critique' => true,
        ]);

        $sante = $this->diagnostiquer($project);

        $this->assertSame(HealthStatus::Rouge, $sante->statut);
        $this->assertTrue($sante->alertes->contains(fn ($a) => $a->code === 'jalons'));
    }

    public function test_un_livrable_obligatoire_en_retard_est_signale(): void
    {
        $project = Project::factory()->create([
            'date_debut' => now()->subMonths(3),
            'date_fin_initiale' => now()->addMonths(3),
            'avancement_pct' => 50,
            'taux_realisation_pct' => 50,
        ]);

        $project->deliverables()->create([
            'phase_id' => Phase::where('code', 'CADRAGE')->value('id'),
            'nom' => 'Plan Projet',
            'obligatoire' => true,
            'statut' => DeliverableStatus::AProduire,
            'date_prevue' => now()->subDays(20),
        ]);

        $sante = $this->diagnostiquer($project);

        $this->assertTrue($sante->alertes->contains(fn ($a) => $a->code === 'livrables'));
        $this->assertSame(HealthStatus::Rouge, $sante->statut);
    }

    public function test_un_avancement_optimiste_face_au_taux_de_realisation_est_signale(): void
    {
        $project = Project::factory()->create([
            'date_debut' => now()->subMonths(3),
            'date_fin_initiale' => now()->addMonths(3),
            'avancement_pct' => 80,
            'taux_realisation_pct' => 40,
        ]);

        $sante = $this->diagnostiquer($project);

        $this->assertTrue($sante->alertes->contains(fn ($a) => $a->code === 'coherence_realisation'));
    }
}
