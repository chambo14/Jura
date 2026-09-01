<?php

namespace Tests\Feature\Mpm;

use App\Enums\ProgressStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\AuditEntry;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Audit\Audit;
use Database\Seeders\MpmReferentialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private Project $projet;

    protected function setUp(): void
    {
        parent::setUp();

        Audit::oublier();
        $this->seed(MpmReferentialSeeder::class);
        $this->projet = Project::factory()->create(['avancement_pct' => 40]);
    }

    public function test_une_variation_davancement_laisse_sa_trace(): void
    {
        $direction = User::factory()->role(UserRole::Direction)->create();

        $this->actingAs($direction);
        $this->projet->update(['avancement_pct' => 75]);

        $ligne = AuditEntry::query()->where('attribut', 'avancement_pct')->firstOrFail();

        $this->assertSame('40', $ligne->ancienne_valeur);
        $this->assertSame('75', $ligne->nouvelle_valeur);
        $this->assertSame($direction->id, $ligne->auteur_id);
        $this->assertSame($this->projet->id, $ligne->project_id);
        $this->assertSame(Project::class, $ligne->auditable_type);
    }

    public function test_une_valeur_inchangee_nencombre_pas_le_journal(): void
    {
        $this->projet->update(['avancement_pct' => 40, 'nom' => 'Nom repris']);

        $this->assertSame(0, AuditEntry::query()->count());
    }

    public function test_le_motif_de_laction_accompagne_le_changement(): void
    {
        $tache = $this->projet->tasks()->create([
            'libelle' => 'Rédiger le cahier des charges',
            'statut' => ProgressStatus::NonDemarre,
        ]);

        Audit::pour('Déplacement sur le tableau', fn () => $tache->update([
            'statut' => ProgressStatus::EnCours,
        ]));

        $ligne = AuditEntry::query()->where('attribut', 'statut')->firstOrFail();

        $this->assertSame('Déplacement sur le tableau', $ligne->motif);
        $this->assertSame('non_demarre', $ligne->ancienne_valeur);
        $this->assertSame('en_cours', $ligne->nouvelle_valeur);
        $this->assertSame(Task::class, $ligne->auditable_type);
    }

    public function test_le_motif_ne_deborde_pas_sur_lecriture_suivante(): void
    {
        Audit::pour('Publication du flash report', fn () => $this->projet->update(['avancement_pct' => 50]));

        $this->projet->update(['avancement_pct' => 60]);

        $motifs = AuditEntry::query()->orderBy('id')->pluck('motif')->all();

        $this->assertSame(['Publication du flash report', null], $motifs);
    }

    public function test_une_carte_est_rattachee_a_lhistorique_de_son_projet(): void
    {
        $tache = $this->projet->tasks()->create([
            'libelle' => 'Tenir le COPIL',
            'statut' => ProgressStatus::NonDemarre,
            'date_echeance' => Date::parse('2026-09-01'),
        ]);

        $tache->update(['date_echeance' => Date::parse('2026-10-15')]);

        $ligne = AuditEntry::query()->where('attribut', 'date_echeance')->firstOrFail();

        $this->assertSame($this->projet->id, $ligne->project_id);
        $this->assertSame('2026-09-01', $ligne->ancienne_valeur);
        $this->assertSame('2026-10-15', $ligne->nouvelle_valeur);
    }

    public function test_un_changement_de_profil_est_journalise(): void
    {
        $membre = User::factory()->role(UserRole::Membre)->create();
        $ancien = $membre->profile_id;

        $membre->update(['profile_id' => User::factory()->role(UserRole::Direction)->create()->profile_id]);

        $ligne = AuditEntry::query()->where('attribut', 'profile_id')->firstOrFail();

        $this->assertSame((string) $ancien, $ligne->ancienne_valeur);
        $this->assertNull($ligne->project_id);
    }

    public function test_longlet_historique_montre_les_changements_du_projet(): void
    {
        $direction = User::factory()->role(UserRole::Direction)->create(['name' => 'Sandrine YAPO']);

        $this->actingAs($direction);
        Audit::pour(
            'Modification de la fiche projet',
            fn () => $this->projet->update(['avancement_pct' => 92, 'statut' => ProjectStatus::Suspendu]),
        );

        $this->get(route('projets.show', $this->projet).'?onglet=historique')
            ->assertOk()
            ->assertSee('% Avancement')
            ->assertSee('Modification de la fiche projet')
            ->assertSee('Sandrine YAPO')
            ->assertSee('Statut');
    }

    public function test_un_projet_sans_changement_le_dit_franchement(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Direction)->create())
            ->get(route('projets.show', $this->projet).'?onglet=historique')
            ->assertOk()
            ->assertSee('Aucun changement consigné');
    }

    /**
     * Une suppression ne laissait aucune trace : l'objet disparaissait, et avec
     * lui la possibilité de savoir qu'il avait existé. C'est précisément ce
     * qu'un journal doit retenir.
     */
    public function test_la_suppression_dune_tache_est_consignee(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create();
        $project = Project::factory()->create(['chef_projet_id' => $chef->id]);

        $tache = $project->tasks()->create([
            'libelle' => 'Recetter le module de paiement',
            'statut' => ProgressStatus::EnCours,
        ]);

        $this->actingAs($chef);
        $tache->delete();

        $ligne = AuditEntry::query()
            ->where('project_id', $project->id)
            ->where('attribut', 'suppression')
            ->firstOrFail();

        $this->assertSame('Recetter le module de paiement', $ligne->ancienne_valeur);
        $this->assertSame($chef->id, $ligne->auteur_id);
    }

    /**
     * Le bouton de l'onglet Tâches passe par le modèle, faute de quoi la
     * suppression échapperait au journal.
     */
    public function test_la_suppression_depuis_lecran_est_consignee(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create();
        $project = Project::factory()->create(['chef_projet_id' => $chef->id]);

        $tache = $project->tasks()->create([
            'libelle' => 'Ouvrir le chantier de recette',
            'statut' => ProgressStatus::NonDemarre,
        ]);

        Livewire::actingAs($chef)
            ->test('pages::projets.onglets.taches', ['project' => $project])
            ->call('supprimerTache', $tache->id);

        $this->assertDatabaseHas('audit_entries', [
            'project_id' => $project->id,
            'attribut' => 'suppression',
            'ancienne_valeur' => 'Ouvrir le chantier de recette',
        ]);
    }
}
