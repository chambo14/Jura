<?php

namespace Tests\Feature\Mpm;

use App\Enums\ProgressStatus;
use App\Enums\UserRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\AvancementService;
use App\Support\Avancement\AvancementEquipe;
use App\Support\Avancement\PointDAvancement;
use Database\Seeders\MpmReferentialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use Tests\TestCase;

class AvancementServiceTest extends TestCase
{
    use RefreshDatabase;

    private Project $projet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MpmReferentialSeeder::class);
        $this->projet = Project::factory()->create();
    }

    private function membre(string $equipe): User
    {
        return User::factory()->role(UserRole::Membre)->create(['equipe' => $equipe]);
    }

    /**
     * @param  array<string, mixed>  $attributs
     */
    private function carte(array $attributs = []): Task
    {
        return $this->projet->tasks()->create([
            'libelle' => 'Carte '.fake()->unique()->numberBetween(1, 100000),
            'statut' => ProgressStatus::NonDemarre,
            ...$attributs,
        ]);
    }

    private function service(): AvancementService
    {
        return app(AvancementService::class);
    }

    public function test_le_taux_est_la_part_de_charge_close(): void
    {
        $this->carte(['charge_jours' => 3, 'statut' => ProgressStatus::Termine, 'date_realisation' => now()]);
        $this->carte(['charge_jours' => 1, 'statut' => ProgressStatus::EnCours]);

        $this->assertSame(75.0, $this->service()->taux($this->projet->tasks()->get()));
    }

    public function test_une_carte_sans_charge_pese_une_journee(): void
    {
        $this->carte(['statut' => ProgressStatus::Termine, 'date_realisation' => now()]);
        $this->carte(['statut' => ProgressStatus::NonDemarre]);

        $this->assertSame(50.0, $this->service()->taux($this->projet->tasks()->get()));
    }

    public function test_une_carte_annulee_ne_pese_ni_dun_cote_ni_de_lautre(): void
    {
        $this->carte(['charge_jours' => 5, 'statut' => ProgressStatus::Termine, 'date_realisation' => now()]);
        $this->carte(['charge_jours' => 5, 'statut' => ProgressStatus::Annule]);

        // Sans l'exclusion, le taux tomberait à 50 %.
        $this->assertSame(100.0, $this->service()->taux($this->projet->tasks()->get()));
    }

    public function test_un_lot_sans_carte_ne_divise_pas_par_zero(): void
    {
        $this->assertSame(0.0, $this->service()->taux($this->projet->tasks()->get()));
        $this->assertSame(0.0, $this->service()->tauxDeclare($this->projet->tasks()->get()));
    }

    public function test_lavancement_declare_compte_les_cartes_encore_ouvertes(): void
    {
        $this->carte(['charge_jours' => 1, 'statut' => ProgressStatus::EnCours, 'avancement_pct' => 60]);
        $this->carte(['charge_jours' => 1, 'statut' => ProgressStatus::NonDemarre]);

        $cartes = $this->projet->tasks()->get();

        // Rien n'est clos : le taux réalisé reste à zéro, le déclaré non.
        $this->assertSame(0.0, $this->service()->taux($cartes));
        $this->assertSame(30.0, $this->service()->tauxDeclare($cartes));
    }

    public function test_le_taux_dune_date_passee_ignore_ce_qui_a_ete_clos_depuis(): void
    {
        foreach ([20, 1] as $recul) {
            $this->carte([
                'charge_jours' => 1,
                'statut' => ProgressStatus::Termine,
                'date_realisation' => Date::now()->subDays($recul),
            ])->forceFill(['created_at' => Date::now()->subDays(30)])->save();
        }

        $cartes = $this->projet->tasks()->get();

        $this->assertSame(100.0, $this->service()->taux($cartes));
        $this->assertSame(50.0, $this->service()->taux($cartes, Date::now()->subDays(10)));
    }

    public function test_une_carte_ouverte_apres_la_date_ne_compte_pas_encore(): void
    {
        $ancienne = $this->carte(['charge_jours' => 1, 'statut' => ProgressStatus::Termine, 'date_realisation' => Date::now()->subDays(30)]);
        $ancienne->forceFill(['created_at' => Date::now()->subDays(40)])->save();

        $recente = $this->carte(['charge_jours' => 1, 'statut' => ProgressStatus::NonDemarre]);
        $recente->forceFill(['created_at' => Date::now()->subDay()])->save();

        $cartes = $this->projet->tasks()->get();

        // Il y a vingt jours, seule la première existait : l'équipe était à 100 %.
        $this->assertSame(100.0, $this->service()->taux($cartes, Date::now()->subDays(20)));
        // L'arrivée de la seconde fait reculer la courbe.
        $this->assertSame(50.0, $this->service()->taux($cartes));
    }

    public function test_levolution_couvre_les_semaines_ou_lequipe_avait_des_cartes(): void
    {
        $this->carte(['statut' => ProgressStatus::EnCours, 'date_debut' => Date::now()->subWeeks(10)]);

        $points = $this->service()->evolution($this->projet->tasks()->get(), 6);

        $this->assertCount(6, $points);
        $this->assertContainsOnlyInstancesOf(PointDAvancement::class, $points);
        $this->assertTrue($points->last()->date->isToday());
    }

    public function test_levolution_ecarte_les_semaines_sans_aucune_carte(): void
    {
        // Une carte entrée dans le périmètre il y a deux semaines : avant, il
        // n'y avait rien à faire, et un zéro s'y lirait comme un retard.
        $this->carte(['statut' => ProgressStatus::EnCours, 'date_debut' => Date::now()->subWeeks(2)]);

        $points = $this->service()->evolution($this->projet->tasks()->get(), 8);

        $this->assertCount(3, $points);
        $this->assertTrue($points->first()->date->isSameDay(Date::now()->subWeeks(2)));
    }

    public function test_une_carte_reprise_dun_suivi_anterieur_compte_depuis_ses_dates(): void
    {
        // Saisie aujourd'hui, mais planifiée il y a un mois : la courbe doit la
        // porter depuis sa date de début, sans quoi tout le périmètre semblerait
        // apparaître le jour de la reprise.
        $this->carte([
            'statut' => ProgressStatus::Termine,
            'date_debut' => Date::now()->subWeeks(4),
            'date_realisation' => Date::now()->subWeeks(3),
        ]);

        $this->assertSame(100.0, $this->service()->taux($this->projet->tasks()->get(), Date::now()->subWeeks(2)));
        $this->assertCount(5, $this->service()->evolution($this->projet->tasks()->get(), 8));
    }

    public function test_les_cartes_sont_reparties_par_equipe_de_lassigne(): void
    {
        $monetique = $this->membre('Monétique');
        $digitale = $this->membre('Banque digitale');

        $this->carte(['assignee_id' => $monetique->id, 'charge_jours' => 2, 'statut' => ProgressStatus::Termine, 'date_realisation' => now()]);
        $this->carte(['assignee_id' => $monetique->id, 'charge_jours' => 2, 'statut' => ProgressStatus::EnCours]);
        $this->carte(['assignee_id' => $digitale->id, 'charge_jours' => 1, 'statut' => ProgressStatus::NonDemarre]);
        $this->carte(['charge_jours' => 1, 'statut' => ProgressStatus::NonDemarre]);

        $lignes = $this->service()->parEquipe($this->projet->tasks()->with('assignee')->get());

        $this->assertSame(
            ['Banque digitale', 'Monétique', AvancementService::SANS_EQUIPE],
            $lignes->map(fn (AvancementEquipe $l) => $l->equipe)->all(),
        );

        $monetiques = $lignes->firstWhere('equipe', 'Monétique');
        $this->assertSame(50.0, $monetiques->taux);
        $this->assertSame(2, $monetiques->cartes);
        $this->assertSame(1, $monetiques->terminees);
    }

    public function test_la_progression_compare_les_deux_bouts_de_la_courbe(): void
    {
        $carte = $this->carte(['statut' => ProgressStatus::Termine, 'date_realisation' => Date::now()->subDays(2)]);
        $carte->forceFill(['created_at' => Date::now()->subWeeks(6)])->save();

        $ligne = $this->service()->pour('Monétique', $this->projet->tasks()->get(), 4);

        $this->assertSame(0.0, $ligne->evolution->first()->taux);
        $this->assertSame(100.0, $ligne->evolution->last()->taux);
        $this->assertSame(100.0, $ligne->progression());
    }

    public function test_le_panneau_du_tableau_mesure_les_cartes_affichees(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create(['equipe' => 'Direction des Projets']);
        $this->projet->update(['chef_projet_id' => $chef->id]);

        $monetique = $this->membre('Monétique');
        $this->carte(['assignee_id' => $monetique->id, 'statut' => ProgressStatus::Termine, 'date_realisation' => now()]);
        $this->carte(['assignee_id' => $monetique->id, 'statut' => ProgressStatus::NonDemarre]);

        $digitale = $this->membre('Banque digitale');
        $this->carte(['assignee_id' => $digitale->id, 'statut' => ProgressStatus::NonDemarre]);

        $tableau = Livewire::actingAs($chef)->test('pages::tableau');

        $lignes = $tableau->instance()->avancement();
        $this->assertSame(50.0, $lignes->firstWhere('equipe', 'Monétique')->taux);
        $this->assertSame(0.0, $lignes->firstWhere('equipe', 'Banque digitale')->taux);

        // Le filtre d'équipe restreint la mesure autant que le tableau.
        $tableau->set('filtreEquipe', 'Monétique');

        $this->assertSame(
            ['Monétique'],
            $tableau->instance()->avancement()->map(fn (AvancementEquipe $l) => $l->equipe)->all(),
        );
    }

    public function test_le_formulaire_projet_reprend_lavancement_des_cartes(): void
    {
        $direction = User::factory()->role(UserRole::Direction)->create();

        $this->carte(['charge_jours' => 3, 'statut' => ProgressStatus::Termine, 'date_realisation' => now()]);
        $this->carte(['charge_jours' => 1, 'statut' => ProgressStatus::EnCours, 'avancement_pct' => 40]);

        Livewire::actingAs($direction)
            ->test('pages::projets.form', ['project' => $this->projet])
            ->call('reprendreDesCartes')
            ->assertSet('tauxRealisation', 75.0)
            ->assertSet('avancement', 85.0);
    }

    public function test_la_courbe_svg_retourne_laxe_vertical(): void
    {
        $carte = $this->carte(['statut' => ProgressStatus::Termine, 'date_realisation' => Date::now()->subDay()]);
        $carte->forceFill(['created_at' => Date::now()->subWeeks(4)])->save();

        $ligne = $this->service()->pour('Monétique', $this->projet->tasks()->get(), 3);

        // Trois points sur une boîte de 100 × 100 : 0 % en bas, 100 % en haut.
        $this->assertSame('0.00,100.00 50.00,100.00 100.00,0.00', $ligne->courbe());
    }
}
