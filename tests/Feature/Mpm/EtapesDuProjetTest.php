<?php

namespace Tests\Feature\Mpm;

use App\Enums\ProgressStatus;
use App\Enums\UserRole;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkflowStep;
use App\Services\ProjectProvisioner;
use Database\Seeders\MpmReferentialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Le cycle d'un projet ne tient pas toujours dans le référentiel.
 *
 * Les dossiers de comité le montrent : e-ZiKash suit ses tests SBS et son
 * autorisation BRB, PI BAGRI ses homologations BCEAO. Aucun de ces jalons
 * n'existe dans un référentiel commun, et n'a de raison d'y entrer. Un projet
 * doit donc pouvoir compléter, renommer et réordonner son propre parcours.
 */
class EtapesDuProjetTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private User $chef;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MpmReferentialSeeder::class);

        $this->chef = User::factory()->role(UserRole::ChefProjet)->create();
        $this->project = Project::factory()->create(['chef_projet_id' => $this->chef->id]);

        app(ProjectProvisioner::class)->provisionner($this->project);
    }

    private function ecran(): Testable
    {
        return Livewire::actingAs($this->chef)
            ->test('pages::projets.onglets.planning', ['project' => $this->project]);
    }

    public function test_une_etape_propre_au_projet_sajoute_au_cycle(): void
    {
        $this->ecran()
            ->set('nouvelleEtape', 'Conclusion SBS V2')
            ->call('ajouterEtape')
            ->assertHasNoErrors();

        // La relation ordonne déjà par rang : la nouvelle étape ferme la liste.
        $etape = $this->project->steps()->get()->last();

        $this->assertNotNull($etape);
        $this->assertNull($etape->workflow_step_id, "L'étape ne renvoie à aucune étape du référentiel.");
        $this->assertSame('Conclusion SBS V2', $etape->intitule());
    }

    /**
     * Renommer une étape sur un projet ne touche pas au référentiel : c'est le
     * même libellé pour tous les autres projets.
     */
    public function test_renommer_une_etape_ne_touche_pas_au_referentiel(): void
    {
        $etape = $this->project->steps()->with('workflowStep')->orderBy('ordre')->firstOrFail();
        $referentiel = $etape->workflowStep;

        $ecran = $this->ecran();
        $etapes = $ecran->get('etapes');
        $etapes[0]['libelle'] = 'Cadrage & collecte des prérequis';

        $ecran->set('etapes', $etapes)->call('enregistrerEtapes')->assertHasNoErrors();

        $this->assertSame('Cadrage & collecte des prérequis', $etape->refresh()->intitule());
        $this->assertSame($referentiel->libelle, $referentiel->refresh()->libelle);
    }

    /**
     * Un libellé identique à celui du référentiel n'est pas stocké : l'étape
     * continue de le suivre si le référentiel évolue.
     */
    public function test_un_libelle_inchange_reste_celui_du_referentiel(): void
    {
        $etape = $this->project->steps()->orderBy('ordre')->firstOrFail();

        $this->ecran()->call('enregistrerEtapes')->assertHasNoErrors();

        $this->assertNull($etape->refresh()->libelle);
    }

    public function test_les_etapes_se_reordonnent(): void
    {
        $avant = $this->project->steps()->orderBy('ordre')->pluck('id')->all();

        $this->ecran()->call('deplacerEtape', 0, 1);

        $apres = $this->project->steps()->orderBy('ordre')->pluck('id')->all();

        $this->assertSame([$avant[1], $avant[0]], array_slice($apres, 0, 2));
    }

    public function test_une_etape_du_referentiel_se_reprend_sans_ressaisie(): void
    {
        $etape = $this->project->steps()->orderBy('ordre')->firstOrFail();
        $referentielId = $etape->workflow_step_id;
        $etape->delete();

        $this->ecran()
            ->set('etapeDuReferentiel', (string) $referentielId)
            ->call('reprendreDuReferentiel')
            ->assertHasNoErrors();

        $this->assertTrue(
            $this->project->steps()->where('workflow_step_id', $referentielId)->exists(),
        );
    }

    /**
     * Le bandeau, l'écran de synthèse et le comité lisent le libellé du projet,
     * pas celui du référentiel — sans quoi une étape propre n'aurait pas de nom.
     */
    public function test_letape_courante_prend_le_libelle_du_projet(): void
    {
        $this->project->steps()->create([
            'workflow_step_id' => null,
            'libelle' => 'Autorisation BRB Wallet',
            'ordre' => 99,
            'statut' => ProgressStatus::EnCours,
        ]);

        $this->assertSame('Autorisation BRB Wallet', $this->project->refresh()->etapeCourante());
    }

    public function test_le_referentiel_reste_la_source_par_defaut(): void
    {
        $premiere = WorkflowStep::query()
            ->where('type_projet', $this->project->type_projet->value)
            ->orderBy('ordre')
            ->firstOrFail();

        $this->assertSame(
            $premiere->libelle,
            $this->project->steps()->orderBy('ordre')->firstOrFail()->intitule(),
        );
    }
}
