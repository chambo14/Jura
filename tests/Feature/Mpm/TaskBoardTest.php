<?php

namespace Tests\Feature\Mpm;

use App\Enums\MemberRole;
use App\Enums\ProgressStatus;
use App\Enums\UserRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\MpmReferentialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TaskBoardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MpmReferentialSeeder::class);
    }

    public function test_le_tableau_du_portefeuille_et_longlet_projet_saffichent(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create(['equipe' => 'Monétique']);
        $project = Project::factory()->create(['chef_projet_id' => $chef->id]);
        $project->tasks()->create(['libelle' => 'Cadrage du besoin']);

        $this->actingAs($chef)
            ->get(route('tableau'))
            ->assertOk()
            ->assertSee('Cadrage du besoin');

        $this->actingAs($chef)
            ->get(route('projets.show', $project).'?onglet=tableau')
            ->assertOk()
            ->assertSee('Cadrage du besoin');
    }

    public function test_deplacer_une_carte_change_le_statut_de_la_tache(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create();
        $project = Project::factory()->create(['chef_projet_id' => $chef->id]);
        $tache = $project->tasks()->create(['libelle' => 'Cadrage', 'statut' => ProgressStatus::NonDemarre]);

        Livewire::actingAs($chef)
            ->test('pages::tableau')
            ->call('deplacer', $tache->id, ProgressStatus::EnCours->value);

        $tache->refresh();

        $this->assertSame(ProgressStatus::EnCours, $tache->statut);
        // Une carte tirée dans « En cours » ne peut plus afficher 0 %.
        $this->assertGreaterThan(0, $tache->avancement_pct);
    }

    public function test_une_carte_lachee_dans_termine_est_datee_du_jour(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create();
        $project = Project::factory()->create(['chef_projet_id' => $chef->id]);
        $tache = $project->tasks()->create(['libelle' => 'Recette', 'statut' => ProgressStatus::EnCours]);

        Livewire::actingAs($chef)
            ->test('pages::tableau')
            ->call('deplacer', $tache->id, ProgressStatus::Termine->value);

        $tache->refresh();

        $this->assertSame(ProgressStatus::Termine, $tache->statut);
        $this->assertSame(100.0, (float) $tache->avancement_pct);
        $this->assertTrue($tache->date_realisation?->isToday());
    }

    public function test_le_rang_dans_la_colonne_suit_la_carte_devant_laquelle_on_la_lache(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create();
        $project = Project::factory()->create(['chef_projet_id' => $chef->id]);

        $premiere = $project->tasks()->create(['libelle' => 'A', 'statut' => ProgressStatus::EnCours, 'ordre' => 1]);
        $deuxieme = $project->tasks()->create(['libelle' => 'B', 'statut' => ProgressStatus::EnCours, 'ordre' => 2]);
        $troisieme = $project->tasks()->create(['libelle' => 'C', 'statut' => ProgressStatus::EnCours, 'ordre' => 3]);

        Livewire::actingAs($chef)
            ->test('pages::tableau')
            ->call('deplacer', $troisieme->id, ProgressStatus::EnCours->value, $premiere->id);

        $ordre = Task::whereIn('id', [$premiere->id, $deuxieme->id, $troisieme->id])
            ->orderBy('ordre')
            ->pluck('libelle')
            ->all();

        $this->assertSame(['C', 'A', 'B'], $ordre);
    }

    public function test_un_sponsor_ne_peut_pas_deplacer_une_carte(): void
    {
        $sponsor = User::factory()->role(UserRole::Sponsor)->create();
        $project = Project::factory()->create(['sponsor_id' => $sponsor->id]);
        $tache = $project->tasks()->create(['libelle' => 'Cadrage', 'statut' => ProgressStatus::NonDemarre]);

        Livewire::actingAs($sponsor)
            ->test('pages::tableau')
            ->call('deplacer', $tache->id, ProgressStatus::EnCours->value)
            ->assertForbidden();

        $this->assertSame(ProgressStatus::NonDemarre, $tache->refresh()->statut);
    }

    public function test_le_tableau_ignore_les_cartes_des_projets_fermes(): void
    {
        $membre = User::factory()->role(UserRole::Membre)->create();
        $sien = Project::factory()->create();
        $sien->members()->create([
            'user_id' => $membre->id,
            'role' => MemberRole::Testeur->value,
            'allocation_pct' => 50,
        ]);
        $sien->tasks()->create(['libelle' => 'Visible', 'statut' => ProgressStatus::EnCours]);

        $autre = Project::factory()->create();
        $cachee = $autre->tasks()->create(['libelle' => 'Invisible', 'statut' => ProgressStatus::EnCours]);

        Livewire::actingAs($membre)
            ->test('pages::tableau')
            ->assertSee('Visible')
            ->assertDontSee('Invisible')
            // Même en visant directement la carte, le déplacement ne prend pas.
            ->call('deplacer', $cachee->id, ProgressStatus::Termine->value);

        $this->assertSame(ProgressStatus::EnCours, $cachee->refresh()->statut);
    }

    public function test_une_carte_se_cree_depuis_le_tableau_du_portefeuille(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create();
        $project = Project::factory()->create(['chef_projet_id' => $chef->id]);

        // Aucun projet n'est filtré : il se choisit dans la fenêtre de saisie.
        Livewire::actingAs($chef)
            ->test('pages::tableau')
            ->call('nouvelleCarte')
            // Le projet se choisit dans la fenêtre : le sélecteur doit y être.
            ->assertSee('Choisir un projet')
            ->set('carteProjet', (string) $project->id)
            ->set('carteLibelle', 'Cadrer le besoin')
            ->call('enregistrerCarte')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('tasks', [
            'project_id' => $project->id,
            'libelle' => 'Cadrer le besoin',
        ]);
    }

    public function test_une_carte_neuve_sans_projet_est_refusee_avec_un_message(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create();
        Project::factory()->create(['chef_projet_id' => $chef->id]);

        Livewire::actingAs($chef)
            ->test('pages::tableau')
            ->call('nouvelleCarte')
            ->set('carteLibelle', 'Carte sans projet')
            ->call('enregistrerCarte')
            ->assertHasErrors('carteProjet');

        $this->assertDatabaseMissing('tasks', ['libelle' => 'Carte sans projet']);
    }

    public function test_le_bouton_de_creation_est_reserve_a_qui_peut_contribuer(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create();
        $sponsor = User::factory()->role(UserRole::Sponsor)->create();
        Project::factory()->create(['chef_projet_id' => $chef->id, 'sponsor_id' => $sponsor->id]);

        $this->assertTrue(
            Livewire::actingAs($chef)->test('pages::tableau')->instance()->peutCreerCarte(),
        );

        $this->assertFalse(
            Livewire::actingAs($sponsor)->test('pages::tableau')->instance()->peutCreerCarte(),
        );
    }

    public function test_commenter_est_ouvert_a_qui_voit_le_projet(): void
    {
        $sponsor = User::factory()->role(UserRole::Sponsor)->create();
        $project = Project::factory()->create(['sponsor_id' => $sponsor->id]);
        $tache = $project->tasks()->create(['libelle' => 'Cadrage']);

        Livewire::actingAs($sponsor)
            ->test('pages::tableau')
            ->call('ouvrirCarte', $tache->id)
            ->set('nouveauCommentaire', 'Le comité attend le chiffrage.')
            ->call('commenter');

        $this->assertDatabaseHas('task_comments', [
            'task_id' => $tache->id,
            'auteur_id' => $sponsor->id,
            'corps' => 'Le comité attend le chiffrage.',
        ]);
    }
}
