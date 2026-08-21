<?php

namespace Tests\Feature\Mpm;

use App\Enums\DeliverableStatus;
use App\Enums\MemberRole;
use App\Enums\ProjectType;
use App\Models\DeliverableType;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkflowStep;
use App\Services\ProjectProvisioner;
use Database\Seeders\MpmReferentialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectProvisionerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MpmReferentialSeeder::class);
    }

    public function test_il_instancie_les_huit_phases_mpm(): void
    {
        $project = Project::factory()->create();

        app(ProjectProvisioner::class)->provisionner($project);

        $this->assertSame(8, $project->phases()->count());
        $this->assertSame(
            ['Opportunité', 'Cadrage', 'Conception', 'Réalisation', 'Qualification', 'Mise en production', 'Exécution', 'Clôture'],
            $project->phases()->with('phase')->get()->map(fn ($p) => $p->phase->nom)->all(),
        );
    }

    public function test_le_bandeau_davancement_depend_du_type_de_projet(): void
    {
        $deploiement = Project::factory()->type(ProjectType::Deploiement)->create();
        $integration = Project::factory()->type(ProjectType::Integration)->create();

        $provisioner = app(ProjectProvisioner::class);
        $provisioner->provisionner($deploiement);
        $provisioner->provisionner($integration);

        $this->assertSame(
            WorkflowStep::forType(ProjectType::Deploiement)->count(),
            $deploiement->steps()->count(),
        );

        $libelles = $integration->steps()->with('workflowStep')->get()
            ->map(fn ($e) => $e->workflowStep->libelle);

        // Étape propre aux projets d'intégration, absente du parcours de déploiement.
        $this->assertTrue($libelles->contains('Adaptation'));
        $this->assertFalse($libelles->contains('Développement'));
    }

    public function test_il_positionne_le_projet_sur_la_premiere_etape(): void
    {
        $project = Project::factory()->create(['workflow_step_id' => null]);

        app(ProjectProvisioner::class)->provisionner($project);

        $premiere = WorkflowStep::forType($project->type_projet)->first();

        $this->assertSame($premiere->id, $project->fresh()->workflow_step_id);
    }

    public function test_il_cree_les_livrables_du_referentiel_a_produire(): void
    {
        $project = Project::factory()->create();

        app(ProjectProvisioner::class)->provisionner($project);

        $attendus = DeliverableType::all()
            ->filter(fn (DeliverableType $type) => $type->appliesTo($project->type_projet))
            ->count();

        $this->assertSame($attendus, $project->deliverables()->count());
        $this->assertSame(
            $attendus,
            $project->deliverables()->where('statut', DeliverableStatus::AProduire->value)->count(),
        );
    }

    public function test_un_livrable_restreint_nest_pas_cree_hors_de_son_type_de_projet(): void
    {
        $migration = DeliverableType::where('nom', 'Plan de migration des données')->firstOrFail();

        $this->assertSame(['deploiement', 'integration'], $migration->types_projet);

        $developpement = Project::factory()->type(ProjectType::Developpement)->create();
        app(ProjectProvisioner::class)->provisionner($developpement);

        $this->assertFalse(
            $developpement->deliverables()->where('deliverable_type_id', $migration->id)->exists(),
        );
    }

    public function test_le_provisionnement_est_rejouable_sans_doublon(): void
    {
        $project = Project::factory()->create();
        $provisioner = app(ProjectProvisioner::class);

        $provisioner->provisionner($project);
        $attendus = $project->deliverables()->count();

        $provisioner->provisionner($project->fresh());

        $this->assertSame($attendus, $project->deliverables()->count());
        $this->assertSame(8, $project->phases()->count());
    }

    public function test_il_reporte_le_pilotage_dans_lequipe_projet(): void
    {
        $chef = User::factory()->create();
        $sponsor = User::factory()->create();

        $project = Project::factory()->create([
            'chef_projet_id' => $chef->id,
            'sponsor_id' => $sponsor->id,
        ]);

        app(ProjectProvisioner::class)->provisionner($project);

        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $chef->id,
            'role' => MemberRole::ChefProjet->value,
        ]);

        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $sponsor->id,
            'role' => MemberRole::Sponsor->value,
        ]);
    }

    public function test_la_resynchronisation_ajoute_les_livrables_manquants(): void
    {
        $project = Project::factory()->create();
        $provisioner = app(ProjectProvisioner::class);
        $provisioner->provisionner($project);

        $project->deliverables()->limit(3)->delete();

        $this->assertSame(3, $provisioner->resynchroniserLivrables($project->fresh()));
        $this->assertSame(0, $provisioner->resynchroniserLivrables($project->fresh()));
    }
}
