<?php

namespace Tests\Feature\Mpm;

use App\Enums\MemberRole;
use App\Enums\UserRole;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectHealthService;
use App\Services\ProjectProvisioner;
use Database\Seeders\MpmReferentialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MpmReferentialSeeder::class);
    }

    private function projet(User $chef): Project
    {
        $projet = Project::factory()->create([
            'chef_projet_id' => $chef->id,
            'date_debut' => '2026-01-05',
            'date_fin_initiale' => '2026-06-30',
            'date_fin_revisee' => null,
        ]);

        app(ProjectProvisioner::class)->provisionner($projet);

        return $projet->fresh();
    }

    public function test_le_pilotage_peut_reporter_la_date_de_fin(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create();
        $projet = $this->projet($chef);

        Livewire::actingAs($chef)
            ->test('pages::projets.onglets.planning', ['project' => $projet])
            ->set('dateFinRevisee', '2026-09-30')
            ->call('enregistrerDelais')
            ->assertHasNoErrors();

        $projet->refresh();

        $this->assertSame('2026-09-30', $projet->date_fin_revisee->toDateString());
        // La date d'engagement d'origine n'a pas bougé : c'est elle qui mesure la dérive.
        $this->assertSame('2026-06-30', $projet->date_fin_initiale->toDateString());
        $this->assertSame(92, $projet->glissementJours());
    }

    public function test_la_date_de_fin_ne_peut_pas_preceder_le_debut(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create();
        $projet = $this->projet($chef);

        Livewire::actingAs($chef)
            ->test('pages::projets.onglets.planning', ['project' => $projet])
            ->set('dateFinRevisee', '2025-12-01')
            ->call('enregistrerDelais')
            ->assertHasErrors(['dateFinRevisee']);

        $this->assertNull($projet->fresh()->date_fin_revisee);
    }

    public function test_vider_la_date_revisee_ramene_a_lengagement_initial(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create();
        $projet = $this->projet($chef);
        $projet->update(['date_fin_revisee' => '2026-09-30']);

        Livewire::actingAs($chef)
            ->test('pages::projets.onglets.planning', ['project' => $projet->fresh()])
            ->set('dateFinRevisee', '')
            ->call('enregistrerDelais')
            ->assertHasNoErrors();

        $projet->refresh();

        $this->assertNull($projet->date_fin_revisee);
        $this->assertSame('2026-06-30', $projet->dateFinEffective()->toDateString());
        $this->assertSame(0, $projet->glissementJours());
    }

    public function test_un_membre_ne_peut_pas_modifier_les_delais(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create();
        $projet = $this->projet($chef);

        $membre = User::factory()->role(UserRole::Membre)->create();
        $projet->members()->create([
            'user_id' => $membre->id,
            'role' => MemberRole::Testeur->value,
            'allocation_pct' => 50,
        ]);

        Livewire::actingAs($membre)
            ->test('pages::projets.onglets.planning', ['project' => $projet])
            ->set('dateFinRevisee', '2026-12-31')
            ->call('enregistrerDelais')
            ->assertForbidden();

        $this->assertNull($projet->fresh()->date_fin_revisee);
    }

    public function test_le_glissement_alimente_le_diagnostic_du_projet(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create();
        $projet = $this->projet($chef);

        Livewire::actingAs($chef)
            ->test('pages::projets.onglets.planning', ['project' => $projet])
            ->set('dateFinRevisee', Date::parse('2026-06-30')->addMonths(6)->toDateString())
            ->call('enregistrerDelais')
            ->assertHasNoErrors();

        $sante = app(ProjectHealthService::class)->diagnostiquer(
            $projet->fresh(['milestones', 'tasks', 'deliverables']),
        );

        $this->assertTrue($sante->alertes->contains(fn ($a) => $a->code === 'glissement'));
    }
}
