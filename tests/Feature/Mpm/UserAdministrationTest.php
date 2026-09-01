<?php

namespace Tests\Feature\Mpm;

use App\Enums\MemberRole;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

class UserAdministrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Un poste qui annonce le métier en toutes lettres renseigne la fonction
     * tant que celle-ci est vide : sans elle, le compte n'apparaît dans aucune
     * liste de pilotage, et rien à l'écran ne l'explique.
     */
    public function test_le_poste_renseigne_la_fonction_encore_vide(): void
    {
        Livewire::actingAs(User::factory()->role(UserRole::Direction)->create())
            ->test('pages::utilisateurs.onglets.comptes')
            ->set('poste', 'Chef de projet adjoint')
            ->assertSet('fonction', MemberRole::ChefProjet->value);
    }

    public function test_une_fonction_deja_choisie_nest_pas_ecrasee_par_le_poste(): void
    {
        Livewire::actingAs(User::factory()->role(UserRole::Direction)->create())
            ->test('pages::utilisateurs.onglets.comptes')
            ->set('fonction', MemberRole::ReferentTechnique->value)
            ->set('poste', 'Chef de projet')
            ->assertSet('fonction', MemberRole::ReferentTechnique->value);
    }

    public function test_la_racine_renvoie_le_visiteur_vers_la_connexion(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_la_racine_renvoie_lutilisateur_connecte_vers_le_tableau_de_bord(): void
    {
        $this->actingAs(User::factory()->role(UserRole::ChefProjet)->create())
            ->get('/')
            ->assertRedirect(route('dashboard'));
    }

    public function test_linscription_publique_est_fermee(): void
    {
        $this->assertFalse(Route::has('register'));
    }

    public function test_seule_la_direction_accede_a_ladministration_des_comptes(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Direction)->create())
            ->get(route('utilisateurs.index'))
            ->assertOk();

        foreach ([UserRole::ChefProjet, UserRole::Sponsor, UserRole::Membre] as $profil) {
            $this->actingAs(User::factory()->role($profil)->create())
                ->get(route('utilisateurs.index'))
                ->assertForbidden();
        }
    }

    public function test_un_administrateur_ne_peut_pas_se_desactiver(): void
    {
        $direction = User::factory()->role(UserRole::Direction)->create();
        $autre = User::factory()->role(UserRole::Direction)->create();

        $this->assertFalse($direction->can('deactivate', $direction));
        $this->assertTrue($direction->can('deactivate', $autre));
    }

    public function test_les_comptes_ne_sont_jamais_supprimes(): void
    {
        $direction = User::factory()->role(UserRole::Direction)->create();

        $this->assertFalse($direction->can('delete', User::factory()->create()));
    }
}
