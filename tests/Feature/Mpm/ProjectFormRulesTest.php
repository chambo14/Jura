<?php

namespace Tests\Feature\Mpm;

use App\Enums\MemberRole;
use App\Enums\ProgressStatus;
use App\Enums\ProjectCategory;
use App\Enums\ProjectType;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkflowStep;
use App\Services\ProjectProvisioner;
use Database\Seeders\MpmReferentialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectFormRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MpmReferentialSeeder::class);
    }

    private function direction(): User
    {
        return User::factory()->role(UserRole::Direction)->create();
    }

    // ── Rôles de pilotage ──────────────────────────────────────────────────

    public function test_chaque_role_ne_propose_que_les_profils_prevus(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create(['name' => 'Chef']);
        $sponsor = User::factory()->role(UserRole::Sponsor)->create(['name' => 'Sponsor']);
        $membre = User::factory()->role(UserRole::Membre)->create(['name' => 'Membre']);
        $direction = $this->direction();

        $formulaire = Livewire::actingAs($direction)->test('pages::projets.form')->instance();

        $noms = fn (MemberRole $role) => $formulaire->candidats($role)->pluck('name')->sort()->values()->all();

        $attendu = fn (array $noms) => collect($noms)->sort()->values()->all();

        // La Direction supplée partout, donc figure dans chaque liste.
        $this->assertSame($attendu(['Chef', $direction->name]), $noms(MemberRole::ChefProjet));
        $this->assertSame($attendu([$direction->name, 'Sponsor']), $noms(MemberRole::Sponsor));
        $this->assertSame($attendu(['Chef', $direction->name, 'Membre']), $noms(MemberRole::ReferentTechnique));
    }

    public function test_un_sponsor_ne_peut_pas_etre_designe_referent_technique(): void
    {
        $sponsor = User::factory()->role(UserRole::Sponsor)->create();
        $client = Client::factory()->create();

        Livewire::actingAs($this->direction())
            ->test('pages::projets.form')
            ->set('nom', 'Projet test')
            ->set('clientId', (string) $client->id)
            ->set('categorie', ProjectCategory::Monetique->value)
            ->set('referentTechniqueId', (string) $sponsor->id)
            ->call('enregistrer')
            ->assertHasErrors('referentTechniqueId');

        $this->assertNull(Project::firstWhere('nom', 'Projet test'));
    }

    public function test_un_membre_dequipe_peut_etre_referent_technique(): void
    {
        $membre = User::factory()->role(UserRole::Membre)->create();
        $client = Client::factory()->create();

        Livewire::actingAs($this->direction())
            ->test('pages::projets.form')
            ->set('nom', 'Projet test')
            ->set('clientId', (string) $client->id)
            ->set('categorie', ProjectCategory::Monetique->value)
            ->set('referentTechniqueId', (string) $membre->id)
            ->call('enregistrer')
            ->assertHasNoErrors();

        $this->assertSame($membre->id, Project::firstWhere('nom', 'Projet test')?->referent_technique_id);
    }

    /**
     * Une désignation antérieure au filtre ne doit pas bloquer la fiche ni
     * disparaître de la liste : on ne casse pas sur un existant qu'on hérite.
     */
    public function test_une_designation_devenue_non_conforme_reste_proposee(): void
    {
        $sponsor = User::factory()->role(UserRole::Sponsor)->create(['name' => 'Ancien référent']);
        $projet = Project::factory()->create(['referent_technique_id' => $sponsor->id]);

        $formulaire = Livewire::actingAs($this->direction())
            ->test('pages::projets.form', ['project' => $projet]);

        $this->assertTrue(
            $formulaire->instance()->candidats(MemberRole::ReferentTechnique)->contains('name', 'Ancien référent'),
        );

        $formulaire->set('nom', 'Nom repris')->call('enregistrer')->assertHasNoErrors();
    }

    // ── Étapes du projet ───────────────────────────────────────────────────

    public function test_le_formulaire_coche_toutes_les_etapes_du_type(): void
    {
        $formulaire = Livewire::actingAs($this->direction())->test('pages::projets.form');

        $attendues = WorkflowStep::forType(ProjectType::Deploiement)->pluck('id')->all();

        $this->assertSame($attendues, $formulaire->get('etapes'));
    }

    public function test_changer_de_type_recharge_le_catalogue_detapes(): void
    {
        $formulaire = Livewire::actingAs($this->direction())
            ->test('pages::projets.form')
            ->set('typeProjet', ProjectType::Developpement->value);

        $this->assertSame(
            WorkflowStep::forType(ProjectType::Developpement)->pluck('id')->all(),
            $formulaire->get('etapes'),
        );
        $this->assertCount(14, $formulaire->instance()->etapesDisponibles());
    }

    public function test_seules_les_etapes_cochees_sont_instanciees(): void
    {
        $client = Client::factory()->create();
        $retenues = WorkflowStep::forType(ProjectType::Deploiement)->take(4)->pluck('id')->all();

        Livewire::actingAs($this->direction())
            ->test('pages::projets.form')
            ->set('nom', 'Projet allégé')
            ->set('clientId', (string) $client->id)
            ->set('categorie', ProjectCategory::Monetique->value)
            ->set('etapes', $retenues)
            ->call('enregistrer')
            ->assertHasNoErrors();

        $projet = Project::firstWhere('nom', 'Projet allégé');

        $this->assertSame($retenues, $projet->steps()->pluck('workflow_step_id')->all());
        $this->assertSame($retenues[0], $projet->workflow_step_id);
    }

    public function test_un_projet_sans_aucune_etape_est_refuse(): void
    {
        $client = Client::factory()->create();

        Livewire::actingAs($this->direction())
            ->test('pages::projets.form')
            ->set('nom', 'Projet vide')
            ->set('clientId', (string) $client->id)
            ->set('categorie', ProjectCategory::Monetique->value)
            ->call('decocherToutesLesEtapes')
            ->call('enregistrer')
            ->assertHasErrors('etapes');
    }

    public function test_une_etape_decochee_et_vierge_est_retiree(): void
    {
        $projet = Project::factory()->create(['type_projet' => ProjectType::Deploiement]);
        app(ProjectProvisioner::class)->provisionner($projet);

        $gardees = $projet->steps()->pluck('workflow_step_id')->take(5)->all();

        Livewire::actingAs($this->direction())
            ->test('pages::projets.form', ['project' => $projet])
            ->set('etapes', $gardees)
            ->call('enregistrer')
            ->assertHasNoErrors();

        $this->assertSame($gardees, $projet->fresh()->steps()->pluck('workflow_step_id')->all());
    }

    /**
     * Décocher une étape où du travail est consigné effacerait cette trace :
     * l'étape reste, et l'écran le dit.
     */
    public function test_une_etape_decochee_mais_entamee_est_conservee(): void
    {
        $projet = Project::factory()->create(['type_projet' => ProjectType::Deploiement]);
        app(ProjectProvisioner::class)->provisionner($projet);

        $entamee = $projet->steps()->orderByDesc('ordre')->first();
        $entamee->update(['statut' => ProgressStatus::EnCours]);

        Livewire::actingAs($this->direction())
            ->test('pages::projets.form', ['project' => $projet])
            ->set('etapes', [$projet->steps()->orderBy('ordre')->first()->workflow_step_id])
            ->call('enregistrer')
            ->assertHasNoErrors();

        $this->assertTrue(
            $projet->fresh()->steps()->where('workflow_step_id', $entamee->workflow_step_id)->exists(),
            "L'étape entamée aurait dû être conservée.",
        );
    }

    public function test_une_etape_annotee_est_conservee_elle_aussi(): void
    {
        $projet = Project::factory()->create(['type_projet' => ProjectType::Deploiement]);
        app(ProjectProvisioner::class)->provisionner($projet);

        $annotee = $projet->steps()->orderByDesc('ordre')->first();
        $annotee->update(['annotation' => 'UAT 90 %']);

        $premiere = $projet->steps()->orderBy('ordre')->first()->workflow_step_id;

        Livewire::actingAs($this->direction())
            ->test('pages::projets.form', ['project' => $projet])
            ->set('etapes', [$premiere])
            ->call('enregistrer');

        $this->assertTrue(
            $projet->fresh()->steps()->where('workflow_step_id', $annotee->workflow_step_id)->exists(),
        );
    }

    public function test_letape_courante_suit_la_selection(): void
    {
        $projet = Project::factory()->create(['type_projet' => ProjectType::Deploiement]);
        app(ProjectProvisioner::class)->provisionner($projet);

        $this->assertNotNull($projet->fresh()->workflow_step_id);

        // On écarte l'étape sur laquelle le projet se trouvait.
        $restantes = $projet->steps()->orderBy('ordre')->pluck('workflow_step_id')->slice(3)->values()->all();

        app(ProjectProvisioner::class)->provisionner($projet->fresh(), $restantes);

        $this->assertSame($restantes[0], $projet->fresh()->workflow_step_id);
    }
}
