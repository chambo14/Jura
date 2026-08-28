<?php

namespace Tests\Feature\Mpm;

use App\Enums\MemberRole;
use App\Enums\UserRole;
use App\Models\Project;
use App\Models\User;
use App\Services\AttachmentService;
use Database\Seeders\MpmReferentialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MpmReferentialSeeder::class);

        Storage::fake('local');
    }

    public function test_longlet_documents_du_projet_saffiche(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create();
        $project = Project::factory()->create(['chef_projet_id' => $chef->id]);

        $this->actingAs($chef)
            ->get(route('projets.show', $project).'?onglet=documents')
            ->assertOk()
            ->assertSee('Déposer des fichiers');
    }

    public function test_un_fichier_depose_est_range_sous_le_projet(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create();
        $project = Project::factory()->create(['chef_projet_id' => $chef->id]);

        $piece = app(AttachmentService::class)->deposer(
            $project,
            UploadedFile::fake()->create('cahier des charges.pdf', 120, 'application/pdf'),
            $chef,
            'Version 1.2',
        );

        Storage::disk('local')->assertExists($piece->chemin);

        $this->assertSame($project->id, $piece->project_id);
        $this->assertSame($chef->id, $piece->depose_par_id);
        $this->assertSame('cahier des charges.pdf', $piece->nom);
        $this->assertSame('Version 1.2', $piece->description);
        $this->assertStringStartsWith('projets/'.$project->id.'/', $piece->chemin);
    }

    public function test_le_nom_dorigine_ne_sert_jamais_de_nom_de_fichier_sur_le_disque(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create();
        $project = Project::factory()->create(['chef_projet_id' => $chef->id]);

        $piece = app(AttachmentService::class)->deposer(
            $project,
            UploadedFile::fake()->create('../../passwd.txt', 2, 'text/plain'),
            $chef,
        );

        $this->assertStringNotContainsString('passwd', $piece->chemin);
        $this->assertStringStartsWith('projets/'.$project->id.'/', $piece->chemin);
    }

    public function test_une_tache_porte_ses_propres_pieces_jointes(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create();
        $project = Project::factory()->create(['chef_projet_id' => $chef->id]);
        $tache = $project->tasks()->create(['libelle' => 'Recette']);

        $piece = app(AttachmentService::class)->deposer(
            $tache,
            UploadedFile::fake()->create('pv-recette.pdf', 30, 'application/pdf'),
            $chef,
        );

        // Le fichier suit la tâche, mais reste rattaché au projet pour l'accès.
        $this->assertTrue($tache->attachments->contains($piece));
        $this->assertSame($project->id, $piece->project_id);
        $this->assertSame('Tâche — Recette', $piece->rattachement());
    }

    public function test_le_telechargement_est_refuse_a_qui_ne_voit_pas_le_projet(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create();
        $project = Project::factory()->create(['chef_projet_id' => $chef->id]);

        $piece = app(AttachmentService::class)->deposer(
            $project,
            UploadedFile::fake()->create('note.pdf', 10, 'application/pdf'),
            $chef,
        );

        $etranger = User::factory()->role(UserRole::Membre)->create();

        $this->actingAs($etranger)->get(route('documents.download', $piece))->assertForbidden();
    }

    public function test_un_membre_affecte_telecharge_le_fichier(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create();
        $project = Project::factory()->create(['chef_projet_id' => $chef->id]);

        $piece = app(AttachmentService::class)->deposer(
            $project,
            UploadedFile::fake()->create('note.pdf', 10, 'application/pdf'),
            $chef,
        );

        $membre = User::factory()->role(UserRole::Membre)->create();
        $project->members()->create([
            'user_id' => $membre->id,
            'role' => MemberRole::Testeur->value,
            'allocation_pct' => 50,
        ]);

        $this->actingAs($membre)
            ->get(route('documents.download', $piece))
            ->assertOk()
            ->assertDownload('note.pdf');
    }

    /**
     * Lire sans télécharger : le fichier s'affiche dans l'onglet, ce qui évite
     * de sortir de l'application pour consulter une note de cadrage.
     */
    public function test_un_pdf_se_lit_dans_le_navigateur(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create();
        $project = Project::factory()->create(['chef_projet_id' => $chef->id]);

        $piece = app(AttachmentService::class)->deposer(
            $project,
            UploadedFile::fake()->createWithContent('note de cadrage.pdf', '%PDF-1.4 contenu'),
            $chef,
        );

        $reponse = $this->actingAs($chef)->get(route('documents.lire', $piece))->assertOk();

        $this->assertSame('application/pdf', $reponse->headers->get('content-type'));
        $this->assertStringStartsWith('inline', (string) $reponse->headers->get('content-disposition'));
        $this->assertSame('nosniff', $reponse->headers->get('x-content-type-options'));
    }

    /**
     * Le fichier est servi depuis l'origine de l'application : un document
     * capable d'exécuter du script y emprunterait la session de son lecteur.
     * Le dépôt accepte le SVG, la lecture le refuse.
     */
    public function test_un_document_actif_ne_se_lit_pas_dans_le_navigateur(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create();
        $project = Project::factory()->create(['chef_projet_id' => $chef->id]);

        $piece = app(AttachmentService::class)->deposer(
            $project,
            UploadedFile::fake()->createWithContent(
                'logo.svg',
                '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            ),
            $chef,
        );

        $this->actingAs($chef)->get(route('documents.lire', $piece))->assertNotFound();
        $this->actingAs($chef)->get(route('documents.download', $piece))->assertOk();
    }

    /**
     * Le type servi vient du contenu réel, jamais de ce qu'annonçait le
     * navigateur au dépôt : autrement, il suffirait de mentir à l'envoi.
     */
    public function test_un_fichier_deguise_en_pdf_ne_se_lit_pas(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create();
        $project = Project::factory()->create(['chef_projet_id' => $chef->id]);

        $piece = app(AttachmentService::class)->deposer(
            $project,
            UploadedFile::fake()->createWithContent(
                'piege.pdf',
                '<html><script>alert(1)</script></html>',
            ),
            $chef,
        );

        $this->actingAs($chef)->get(route('documents.lire', $piece))->assertNotFound();
    }

    public function test_la_lecture_suit_les_droits_du_projet(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create();
        $project = Project::factory()->create(['chef_projet_id' => $chef->id]);

        $piece = app(AttachmentService::class)->deposer(
            $project,
            UploadedFile::fake()->createWithContent('note.pdf', '%PDF-1.4 contenu'),
            $chef,
        );

        $etranger = User::factory()->role(UserRole::Membre)->create();

        $this->actingAs($etranger)->get(route('documents.lire', $piece))->assertForbidden();
    }

    public function test_retirer_une_piece_efface_aussi_le_fichier(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create();
        $project = Project::factory()->create(['chef_projet_id' => $chef->id]);

        $service = app(AttachmentService::class);
        $piece = $service->deposer(
            $project,
            UploadedFile::fake()->create('brouillon.docx', 10),
            $chef,
        );

        $chemin = $piece->chemin;
        $service->supprimer($piece);

        Storage::disk('local')->assertMissing($chemin);
        $this->assertDatabaseMissing('attachments', ['id' => $piece->id]);
    }
}
