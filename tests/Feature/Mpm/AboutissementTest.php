<?php

namespace Tests\Feature\Mpm;

use App\Enums\DeliverableStatus;
use App\Enums\ProgressStatus;
use App\Enums\UserRole;
use App\Models\Deliverable;
use App\Models\Phase;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\User;
use App\Services\AboutissementService;
use App\Services\AttachmentService;
use App\Services\ProjectHealthService;
use App\Services\ProjectProvisioner;
use Database\Seeders\MpmReferentialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * L'aboutissement d'une étape se mesure aux pièces produites, pas au statut
 * déclaré. Une phase de cadrage donnée pour terminée sans note de cadrage
 * laisse le projet sans le document sur lequel toute la suite s'appuie : c'est
 * cela que l'application doit dire.
 */
class AboutissementTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $chef;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MpmReferentialSeeder::class);
        Storage::fake('local');

        $this->chef = User::factory()->role(UserRole::ChefProjet)->create();
        $this->project = Project::factory()->create(['chef_projet_id' => $this->chef->id]);

        app(ProjectProvisioner::class)->provisionner($this->project);
    }

    private function phase(string $nom): ProjectPhase
    {
        $phase = Phase::query()->where('nom', $nom)->firstOrFail();

        return $this->project->phases()->where('phase_id', $phase->id)->firstOrFail();
    }

    /**
     * Les livrables encore dus sur une phase, par leur nom.
     *
     * @return array<int, string>
     */
    private function manquantsDe(string $nom): array
    {
        $constat = app(AboutissementService::class)
            ->phasesNonAbouties($this->project->fresh())
            ->firstWhere(fn ($c) => $c->phase->nom === $nom);

        return $constat === null ? [] : $constat->manquants->map(fn ($l) => $l->nom)->all();
    }

    private function noteDeCadrage(): Deliverable
    {
        return $this->project->deliverables()
            ->where('phase_id', $this->phase('Cadrage')->phase_id)
            ->where('obligatoire', true)
            ->firstOrFail();
    }

    public function test_une_phase_non_atteinte_ne_doit_rien(): void
    {
        $constats = app(AboutissementService::class)->phasesNonAbouties($this->project->fresh());

        $this->assertTrue($constats->isEmpty());
    }

    public function test_une_phase_terminee_sans_sa_piece_nest_pas_aboutie(): void
    {
        $this->phase('Cadrage')->update(['statut' => ProgressStatus::Termine]);

        $constats = app(AboutissementService::class)->phasesNonAbouties($this->project->fresh());

        $cadrage = $constats->firstWhere(fn ($c) => $c->phase->nom === 'Cadrage');

        $this->assertNotNull($cadrage);
        $this->assertTrue($cadrage->grave());
        $this->assertStringContainsString($this->noteDeCadrage()->nom, $cadrage->libelle());
    }

    /**
     * On ne cadre plus quand on développe : une phase que les suivantes ont
     * dépassée doit avoir produit ses pièces, même sans être close.
     */
    public function test_une_phase_depassee_par_la_suivante_nest_pas_aboutie(): void
    {
        $this->phase('Réalisation')->update(['statut' => ProgressStatus::EnCours]);

        $constats = app(AboutissementService::class)->phasesNonAbouties($this->project->fresh());

        $this->assertContains('Cadrage', $constats->map(fn ($c) => $c->phase->nom)->all());
        $this->assertFalse(
            $constats->firstWhere(fn ($c) => $c->phase->nom === 'Cadrage')->grave(),
            'Une phase seulement dépassée est un retard, pas une contradiction.',
        );
    }

    public function test_verser_la_piece_rend_la_phase_aboutie(): void
    {
        $this->phase('Cadrage')->update(['statut' => ProgressStatus::Termine]);

        app(AttachmentService::class)->deposer(
            $this->noteDeCadrage(),
            UploadedFile::fake()->createWithContent('note de cadrage.pdf', '%PDF-1.4 note'),
            $this->chef,
        );

        $this->assertNotContains(
            $this->noteDeCadrage()->nom,
            $this->manquantsDe('Cadrage'),
        );
    }

    /**
     * Un lien vers un document tenu ailleurs vaut pièce : ce qui est refusé,
     * c'est l'absence de toute trace.
     */
    public function test_un_lien_vaut_piece(): void
    {
        $this->phase('Cadrage')->update(['statut' => ProgressStatus::Termine]);
        $this->noteDeCadrage()->update(['lien' => 'https://exemple.test/note.pdf']);

        $this->assertNotContains(
            $this->noteDeCadrage()->nom,
            $this->manquantsDe('Cadrage'),
        );
    }

    public function test_un_livrable_ecarte_ou_facultatif_ne_bloque_pas(): void
    {
        $this->phase('Cadrage')->update(['statut' => ProgressStatus::Termine]);

        $this->project->deliverables()
            ->where('phase_id', $this->phase('Cadrage')->phase_id)
            ->update(['statut' => DeliverableStatus::NonApplicable]);

        $constats = app(AboutissementService::class)->phasesNonAbouties($this->project->fresh());

        $this->assertNotContains('Cadrage', $constats->map(fn ($c) => $c->phase->nom)->all());
    }

    public function test_le_diagnostic_du_projet_porte_le_constat(): void
    {
        $this->phase('Cadrage')->update(['statut' => ProgressStatus::Termine]);

        $sante = app(ProjectHealthService::class)->diagnostiquer($this->project->fresh());

        $alerte = $sante->alertes->firstWhere('code', 'aboutissement');

        $this->assertNotNull($alerte, 'Le diagnostic doit signaler une étape non aboutie.');
        $this->assertStringContainsString('non aboutie', $alerte->libelle);
    }

    /**
     * Un livrable coché « validé » sans rien à montrer : le statut se déclare,
     * la pièce se produit.
     */
    public function test_un_livrable_valide_sans_piece_est_signale(): void
    {
        $livrable = $this->noteDeCadrage();
        $livrable->update(['statut' => DeliverableStatus::Valide]);

        $this->assertTrue($livrable->fresh()->pieceManquante());

        app(AttachmentService::class)->deposer(
            $livrable,
            UploadedFile::fake()->createWithContent('note.pdf', '%PDF-1.4 note'),
            $this->chef,
        );

        $this->assertFalse($livrable->fresh()->pieceManquante());
    }

    /**
     * Le dépôt se fait sur le livrable lui-même, depuis l'onglet où on le
     * consulte : c'est là qu'on constate qu'il manque.
     */
    public function test_un_fichier_se_verse_depuis_longlet_livrables(): void
    {
        $this->phase('Cadrage')->update(['statut' => ProgressStatus::Termine]);

        $note = $this->noteDeCadrage();

        Livewire::actingAs($this->chef)
            ->test('pages::projets.onglets.livrables', ['project' => $this->project])
            ->call('deposerSur', $note->id)
            ->set('fichiers', [UploadedFile::fake()->createWithContent('note de cadrage.pdf', '%PDF-1.4 note')])
            ->call('enregistrerPieces')
            ->assertHasNoErrors();

        $this->assertSame(1, $note->attachments()->count());
        $this->assertNotContains($note->nom, $this->manquantsDe('Cadrage'));
    }

    public function test_longlet_livrables_annonce_letape_non_aboutie(): void
    {
        $this->phase('Cadrage')->update(['statut' => ProgressStatus::Termine]);

        $this->actingAs($this->chef)
            ->get(route('projets.show', $this->project).'?onglet=livrables')
            ->assertOk()
            ->assertSee('Étape non aboutie');
    }
}
