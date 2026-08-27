<?php

namespace Tests\Feature\Mpm;

use App\Enums\ProjectCategory;
use App\Enums\ProjectType;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectCodeGenerator;
use Database\Seeders\MpmReferentialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MpmReferentialSeeder::class);
        config()->set('projets.code.prefixe', 'MLP');
        config()->set('projets.code.chiffres', 4);
    }

    private function generateur(): ProjectCodeGenerator
    {
        return app(ProjectCodeGenerator::class);
    }

    public function test_le_premier_projet_recoit_le_numero_un(): void
    {
        $this->assertSame('MLP-0001', $this->generateur()->suivant());
    }

    public function test_la_suite_reprend_au_plus_grand_numero_attribue(): void
    {
        Project::factory()->create(['code' => 'MLP-0001']);
        Project::factory()->create(['code' => 'MLP-0007']);
        Project::factory()->create(['code' => 'MLP-0003']);

        $this->assertSame('MLP-0008', $this->generateur()->suivant());
    }

    /**
     * Les codes hérités d'avant la règle — BRB-IPS-LOT1 et consorts — ne
     * portent pas le préfixe et ne doivent pas perturber le compteur.
     */
    public function test_les_codes_herites_sont_ignores_du_calcul(): void
    {
        Project::factory()->create(['code' => 'BRB-IPS-LOT1']);
        Project::factory()->create(['code' => 'CORIS-VEROPAY']);

        $this->assertSame('MLP-0001', $this->generateur()->suivant());
    }

    public function test_un_suffixe_non_numerique_du_bon_prefixe_ne_compte_pas(): void
    {
        Project::factory()->create(['code' => 'MLP-LOT2']);
        Project::factory()->create(['code' => 'MLP-0002']);

        $this->assertSame('MLP-0003', $this->generateur()->suivant());
    }

    public function test_un_numero_supprime_nest_pas_reattribue(): void
    {
        Project::factory()->create(['code' => 'MLP-0001']);
        $dernier = Project::factory()->create(['code' => 'MLP-0002']);

        $dernier->delete();

        // Rouvrir le 0002 rendrait deux projets indiscernables dans les
        // comptes rendus déjà diffusés.
        $this->assertSame('MLP-0002', $this->generateur()->suivant());
    }

    public function test_chaque_prefixe_a_sa_propre_suite(): void
    {
        Project::factory()->create(['code' => 'MLP-0004']);

        config()->set('projets.code.prefixe', 'DPO');

        $this->assertSame('DPO-0001', $this->generateur()->suivant());
    }

    public function test_le_formulaire_de_creation_affiche_le_code_attribue(): void
    {
        Project::factory()->create(['code' => 'MLP-0012']);

        Livewire::actingAs(User::factory()->role(UserRole::Direction)->create())
            ->test('pages::projets.form')
            ->assertSet('code', 'MLP-0013')
            ->assertSet('codePersonnalise', false);
    }

    public function test_le_formulaire_de_modification_garde_le_code_du_projet(): void
    {
        $projet = Project::factory()->create(['code' => 'BRB-IPS-LOT1']);

        Livewire::actingAs(User::factory()->role(UserRole::Direction)->create())
            ->test('pages::projets.form', ['project' => $projet])
            ->assertSet('code', 'BRB-IPS-LOT1')
            ->assertSet('codePersonnalise', true);
    }

    public function test_le_code_attribue_est_celui_du_projet_cree(): void
    {
        $client = Client::factory()->create();

        Livewire::actingAs(User::factory()->role(UserRole::Direction)->create())
            ->test('pages::projets.form')
            ->set('nom', 'Refonte du portail client')
            ->set('clientId', (string) $client->id)
            ->set('typeProjet', ProjectType::cases()[0]->value)
            ->set('categorie', ProjectCategory::Monetique->value)
            ->call('enregistrer')
            ->assertHasNoErrors();

        $this->assertSame('MLP-0001', Project::firstWhere('nom', 'Refonte du portail client')?->code);
    }

    public function test_le_code_peut_etre_repris_en_main_puis_rendu(): void
    {
        $composant = Livewire::actingAs(User::factory()->role(UserRole::Direction)->create())
            ->test('pages::projets.form')
            ->call('personnaliserLeCode')
            ->assertSet('codePersonnalise', true)
            ->set('code', 'BBCI-INTEROP');

        $composant->call('reprendreLeCodeAutomatique')
            ->assertSet('codePersonnalise', false)
            ->assertSet('code', 'MLP-0001');
    }

    public function test_un_code_saisi_a_la_main_est_conserve(): void
    {
        $client = Client::factory()->create();

        Livewire::actingAs(User::factory()->role(UserRole::Direction)->create())
            ->test('pages::projets.form')
            ->call('personnaliserLeCode')
            ->set('code', 'BBCI-INTEROP')
            ->set('nom', 'Interopérabilité BBCI')
            ->set('clientId', (string) $client->id)
            ->set('typeProjet', ProjectType::cases()[0]->value)
            ->set('categorie', ProjectCategory::Monetique->value)
            ->call('enregistrer')
            ->assertHasNoErrors();

        $this->assertSame('BBCI-INTEROP', Project::firstWhere('nom', 'Interopérabilité BBCI')?->code);
    }
}
