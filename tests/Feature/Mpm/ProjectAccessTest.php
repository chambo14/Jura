<?php

namespace Tests\Feature\Mpm;

use App\Enums\MemberRole;
use App\Enums\UserRole;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\MpmReferentialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MpmReferentialSeeder::class);
    }

    public function test_la_direction_voit_tout_le_portefeuille(): void
    {
        $direction = User::factory()->role(UserRole::Direction)->create();
        Project::factory()->count(3)->create();

        $this->assertSame(3, Project::visibleTo($direction)->count());
    }

    public function test_un_chef_de_projet_ne_voit_que_ses_projets(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create();

        $sien = Project::factory()->create(['chef_projet_id' => $chef->id]);
        Project::factory()->count(2)->create();

        $visibles = Project::visibleTo($chef)->pluck('id');

        $this->assertSame([$sien->id], $visibles->all());
    }

    public function test_un_membre_affecte_voit_le_projet(): void
    {
        $membre = User::factory()->role(UserRole::Membre)->create();
        $project = Project::factory()->create();

        $this->assertSame(0, Project::visibleTo($membre)->count());

        $project->members()->create([
            'user_id' => $membre->id,
            'role' => MemberRole::Testeur->value,
            'allocation_pct' => 50,
        ]);

        $this->assertSame(1, Project::visibleTo($membre)->count());
    }

    public function test_seuls_la_direction_et_les_chefs_de_projet_peuvent_creer(): void
    {
        $this->assertTrue(User::factory()->role(UserRole::Direction)->create()->can('create', Project::class));
        $this->assertTrue(User::factory()->role(UserRole::ChefProjet)->create()->can('create', Project::class));
        $this->assertFalse(User::factory()->role(UserRole::Sponsor)->create()->can('create', Project::class));
        $this->assertFalse(User::factory()->role(UserRole::Membre)->create()->can('create', Project::class));
    }

    /**
     * Modifier le cadrage demande deux conditions : un profil qui porte le droit
     * de pilotage, et un rattachement au projet comme chef ou back-up.
     */
    public function test_le_cadrage_demande_le_droit_de_pilotage_et_un_rattachement(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create();
        $backUp = User::factory()->role(UserRole::ChefProjet)->create();
        $autreChef = User::factory()->role(UserRole::ChefProjet)->create();

        $project = Project::factory()->create([
            'chef_projet_id' => $chef->id,
            'back_up_id' => $backUp->id,
        ]);

        $this->assertTrue($chef->can('update', $project));
        $this->assertTrue($backUp->can('update', $project));

        // Rattachement sans droit de pilotage : pas d'accès au cadrage.
        $this->assertFalse($autreChef->can('update', $project));
    }

    public function test_un_back_up_sans_droit_de_pilotage_ne_touche_pas_au_cadrage(): void
    {
        $backUp = User::factory()->role(UserRole::Membre)->create();

        $project = Project::factory()->create([
            'chef_projet_id' => User::factory()->role(UserRole::ChefProjet)->create()->id,
            'back_up_id' => $backUp->id,
        ]);

        $this->assertFalse($backUp->can('update', $project));
        $this->assertTrue($backUp->can('contribute', $project));
    }

    public function test_un_sponsor_est_en_lecture_seule(): void
    {
        $sponsor = User::factory()->role(UserRole::Sponsor)->create();
        $project = Project::factory()->create(['sponsor_id' => $sponsor->id]);

        $this->assertTrue($sponsor->can('view', $project));
        $this->assertFalse($sponsor->can('update', $project));
        $this->assertFalse($sponsor->can('contribute', $project));
    }

    public function test_un_membre_affecte_peut_contribuer_sans_modifier_le_cadrage(): void
    {
        $membre = User::factory()->role(UserRole::Membre)->create();
        $project = Project::factory()->create(['referent_technique_id' => $membre->id]);

        $this->assertTrue($membre->can('view', $project));
        $this->assertTrue($membre->can('contribute', $project));
        $this->assertFalse($membre->can('update', $project));
    }

    public function test_un_utilisateur_desactive_na_plus_acces(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create(['actif' => false]);
        $project = Project::factory()->create(['chef_projet_id' => $chef->id]);

        $this->assertFalse($chef->can('view', $project));
        $this->assertFalse($chef->can('update', $project));
    }

    public function test_la_fiche_projet_est_refusee_hors_perimetre(): void
    {
        $intrus = User::factory()->role(UserRole::ChefProjet)->create();
        $project = Project::factory()->create();

        $this->actingAs($intrus)
            ->get(route('projets.show', $project))
            ->assertForbidden();
    }
}
