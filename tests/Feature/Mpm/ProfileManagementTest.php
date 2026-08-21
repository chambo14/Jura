<?php

namespace Tests\Feature\Mpm;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Profile;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\MpmReferentialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MpmReferentialSeeder::class);
    }

    public function test_les_quatre_profils_mpm_sont_livres_en_systeme(): void
    {
        $this->assertSame(4, Profile::where('systeme', true)->count());

        foreach (UserRole::cases() as $role) {
            $this->assertDatabaseHas('profiles', ['code' => $role->value, 'systeme' => true]);
        }
    }

    public function test_un_profil_systeme_nest_pas_supprimable(): void
    {
        $direction = User::factory()->role(UserRole::Direction)->create();
        $profil = Profile::where('code', UserRole::Direction->value)->firstOrFail();

        $this->assertFalse($profil->supprimable());
        $this->assertFalse($direction->can('delete', $profil));
    }

    public function test_un_profil_cree_est_supprimable_tant_quil_nest_pas_attribue(): void
    {
        $direction = User::factory()->role(UserRole::Direction)->create();

        $profil = Profile::create([
            'code' => 'qualite',
            'nom' => 'Responsable qualité',
            'ordre' => 10,
            Permission::VoitToutLePortefeuille->value => true,
        ]);

        $this->assertTrue($direction->can('delete', $profil));

        User::factory()->create(['profile_id' => $profil->id]);

        $this->assertFalse($profil->fresh()->supprimable());
    }

    /**
     * Les profils sont un onglet de l'écran Utilisateurs : même porte d'entrée,
     * donc même contrôle d'accès.
     */
    public function test_seule_ladministration_accede_a_longlet_des_profils(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Direction)->create())
            ->get(route('utilisateurs.index', ['onglet' => 'profils']))
            ->assertOk();

        foreach ([UserRole::ChefProjet, UserRole::Sponsor, UserRole::Membre] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get(route('utilisateurs.index', ['onglet' => 'profils']))
                ->assertForbidden();
        }
    }

    public function test_lancienne_adresse_des_profils_mene_a_longlet(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Direction)->create())
            ->get(route('profils.index'))
            ->assertRedirect('utilisateurs?onglet=profils');
    }

    public function test_un_profil_sur_mesure_accorde_exactement_ses_droits(): void
    {
        $profil = Profile::create([
            'code' => 'observateur',
            'nom' => 'Observateur',
            'ordre' => 11,
            Permission::VoitToutLePortefeuille->value => true,
        ]);

        $observateur = User::factory()->create(['profile_id' => $profil->id]);
        $projet = Project::factory()->create();

        $this->assertTrue($observateur->voitToutLePortefeuille());
        $this->assertTrue($observateur->can('view', $projet));
        $this->assertFalse($observateur->can('create', Project::class));
        $this->assertFalse($observateur->can('update', $projet));
        $this->assertTrue($observateur->estLecteur());
    }

    public function test_le_dernier_profil_administrateur_ne_peut_pas_perdre_ce_droit(): void
    {
        $direction = Profile::where('code', UserRole::Direction->value)->firstOrFail();
        User::factory()->create(['profile_id' => $direction->id]);

        $this->assertFalse($direction->peutPerdreLAdministration());

        // Un second profil administrateur, effectivement attribué, lève le verrou.
        $secours = Profile::create([
            'code' => 'admin_secours',
            'nom' => 'Administrateur de secours',
            'ordre' => 12,
            Permission::GereUtilisateurs->value => true,
        ]);
        User::factory()->create(['profile_id' => $secours->id]);

        $this->assertTrue($direction->fresh()->peutPerdreLAdministration());
    }

    public function test_un_utilisateur_sans_profil_na_aucun_droit(): void
    {
        $orphelin = User::factory()->create(['profile_id' => null]);
        $projet = Project::factory()->create();

        $this->assertFalse($orphelin->voitToutLePortefeuille());
        $this->assertFalse($orphelin->peutPiloter());
        $this->assertFalse($orphelin->gereLesUtilisateurs());
        $this->assertTrue($orphelin->estLecteur());
        $this->assertFalse($orphelin->can('view', $projet));
    }
}
