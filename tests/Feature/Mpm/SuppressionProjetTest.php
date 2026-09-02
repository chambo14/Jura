<?php

namespace Tests\Feature\Mpm;

use App\Enums\ProgressStatus;
use App\Enums\UserRole;
use App\Models\Project;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\ProjectProvisioner;
use Database\Seeders\MpmReferentialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Supprimer un projet est sans retour : tout ce qui en dépend disparaît avec
 * lui. Le geste existe pour un projet créé par erreur, et il est fermé par la
 * saisie du code — on ne supprime pas un projet en cliquant à côté.
 */
class SuppressionProjetTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MpmReferentialSeeder::class);
        Storage::fake('local');

        $this->project = Project::factory()->create(['code' => 'MLP-0042']);
        app(ProjectProvisioner::class)->provisionner($this->project);
    }

    private function ecran(User $utilisateur): Testable
    {
        return Livewire::actingAs($utilisateur)
            ->test('pages::projets.form', ['project' => $this->project]);
    }

    public function test_la_direction_supprime_un_projet_en_saisissant_son_code(): void
    {
        $direction = User::factory()->role(UserRole::Direction)->create();

        $this->ecran($direction)
            ->set('codeDeSuppression', 'MLP-0042')
            ->call('supprimer')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('projects', ['id' => $this->project->id]);
        $this->assertDatabaseMissing('project_phases', ['project_id' => $this->project->id]);
        $this->assertDatabaseMissing('deliverables', ['project_id' => $this->project->id]);
    }

    public function test_un_code_qui_ne_correspond_pas_ne_supprime_rien(): void
    {
        $direction = User::factory()->role(UserRole::Direction)->create();

        $this->ecran($direction)
            ->set('codeDeSuppression', 'MLP-0043')
            ->call('supprimer')
            ->assertHasErrors('codeDeSuppression');

        $this->assertDatabaseHas('projects', ['id' => $this->project->id]);
    }

    public function test_un_code_vide_ne_supprime_rien(): void
    {
        $direction = User::factory()->role(UserRole::Direction)->create();

        $this->ecran($direction)->call('supprimer')->assertHasErrors('codeDeSuppression');

        $this->assertDatabaseHas('projects', ['id' => $this->project->id]);
    }

    /**
     * Un chef de projet pilote le sien ; il ne le raye pas du portefeuille.
     */
    public function test_un_chef_de_projet_ne_supprime_pas_son_projet(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create();
        $this->project->update(['chef_projet_id' => $chef->id]);

        $this->ecran($chef)
            ->set('codeDeSuppression', 'MLP-0042')
            ->call('supprimer')
            ->assertForbidden();

        $this->assertDatabaseHas('projects', ['id' => $this->project->id]);
    }

    /**
     * La ligne d'une pièce jointe disparaît en cascade ; le fichier, lui,
     * resterait sur le disque sans plus rien pour dire à quoi il servait.
     */
    public function test_les_fichiers_deposes_sont_effaces_du_disque(): void
    {
        $direction = User::factory()->role(UserRole::Direction)->create();

        $tache = $this->project->tasks()->create([
            'libelle' => 'Recetter',
            'statut' => ProgressStatus::EnCours,
        ]);

        $surLeProjet = app(AttachmentService::class)->deposer(
            $this->project,
            UploadedFile::fake()->createWithContent('cahier.pdf', '%PDF-1.4 x'),
            $direction,
        );
        $surLaTache = app(AttachmentService::class)->deposer(
            $tache,
            UploadedFile::fake()->createWithContent('recette.pdf', '%PDF-1.4 y'),
            $direction,
        );

        Storage::disk('local')->assertExists($surLeProjet->chemin);
        Storage::disk('local')->assertExists($surLaTache->chemin);

        $this->ecran($direction)
            ->set('codeDeSuppression', 'MLP-0042')
            ->call('supprimer')
            ->assertHasNoErrors();

        Storage::disk('local')->assertMissing($surLeProjet->chemin);
        Storage::disk('local')->assertMissing($surLaTache->chemin);
    }
}
