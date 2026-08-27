<?php

namespace Tests\Feature\Mpm;

use App\Enums\DeliverableStatus;
use App\Enums\ProgressStatus;
use App\Enums\SuiviEcheance;
use App\Enums\UserRole;
use App\Models\Deliverable;
use App\Models\Phase;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Echeances\Echeances;
use Database\Seeders\MpmReferentialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class EcheancesTest extends TestCase
{
    use RefreshDatabase;

    private Project $projet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MpmReferentialSeeder::class);
        $this->projet = Project::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $attributs
     */
    private function tache(array $attributs = []): Task
    {
        return $this->projet->tasks()->create([
            'libelle' => 'Tâche '.fake()->unique()->numberBetween(1, 100000),
            'statut' => ProgressStatus::NonDemarre,
            ...$attributs,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributs
     */
    private function livrable(array $attributs = []): Deliverable
    {
        return $this->projet->deliverables()->create([
            'phase_id' => Phase::query()->firstOrFail()->id,
            'nom' => 'Livrable '.fake()->unique()->numberBetween(1, 100000),
            'statut' => DeliverableStatus::AProduire,
            'obligatoire' => true,
            ...$attributs,
        ]);
    }

    public function test_les_quatre_situations_sont_distinguees(): void
    {
        $this->assertSame(
            SuiviEcheance::EnRetard,
            $this->tache(['date_echeance' => Date::now()->subDay()])->suiviEcheance(),
        );

        $this->assertSame(
            SuiviEcheance::ALHeure,
            $this->tache(['date_echeance' => Date::now()->addWeek()])->suiviEcheance(),
        );

        $this->assertSame(
            SuiviEcheance::SansEcheance,
            $this->tache()->suiviEcheance(),
        );

        $this->assertSame(
            SuiviEcheance::NonApplicable,
            $this->tache(['statut' => ProgressStatus::Termine, 'date_echeance' => Date::now()->subMonth()])->suiviEcheance(),
        );
    }

    /**
     * Le constat central de l'audit : sans échéance saisie, un écran affichait
     * sereinement « 0 en retard ».
     */
    public function test_sans_aucune_echeance_le_compteur_refuse_dafficher_zero(): void
    {
        $echeances = Echeances::de([$this->tache(), $this->tache(), $this->tache()]);

        $this->assertFalse($echeances->mesurable());
        $this->assertSame('Non mesurable', $echeances->valeur());
        $this->assertSame('amber', $echeances->couleur());
        $this->assertStringContainsString('aucune échéance saisie', (string) $echeances->precision());
    }

    public function test_un_lot_entierement_clos_reste_mesurable(): void
    {
        $echeances = Echeances::de([
            $this->tache(['statut' => ProgressStatus::Termine]),
            $this->tache(['statut' => ProgressStatus::Annule]),
        ]);

        // Rien d'ouvert : il n'y a rien à mesurer, mais « 0 en retard » est vrai.
        $this->assertTrue($echeances->mesurable());
        $this->assertSame('0', $echeances->valeur());
        $this->assertSame('green', $echeances->couleur());
    }

    public function test_les_elements_sans_date_sont_signales_a_part(): void
    {
        $echeances = Echeances::de([
            $this->tache(['date_echeance' => Date::now()->subDay()]),
            $this->tache(['date_echeance' => Date::now()->addWeek()]),
            $this->tache(),
            $this->tache(),
        ]);

        $this->assertSame(1, $echeances->enRetard);
        $this->assertSame(1, $echeances->aLHeure);
        $this->assertSame(2, $echeances->sansEcheance);
        $this->assertSame(2, $echeances->mesurables());
        $this->assertSame('1', $echeances->valeur());
        $this->assertSame('2 sans échéance, hors du calcul', $echeances->precision());
    }

    public function test_un_livrable_soumis_nest_plus_suivi_en_echeance(): void
    {
        $livrable = $this->livrable([
            'statut' => DeliverableStatus::Soumis,
            'date_prevue' => Date::now()->subMonth(),
        ]);

        $this->assertSame(SuiviEcheance::NonApplicable, $livrable->suiviEcheance());
        $this->assertFalse($livrable->enRetard());
    }

    public function test_lecran_des_livrables_annonce_labsence_de_mesure(): void
    {
        $this->livrable(['nom' => 'Procès-verbal de recette']);

        $this->actingAs(User::factory()->role(UserRole::Direction)->create())
            ->get(route('projets.show', $this->projet).'?onglet=livrables')
            ->assertOk()
            ->assertSee('Non mesurable')
            ->assertSee('aucune échéance saisie');
    }
}
