<?php

namespace Tests\Feature\Mpm;

use App\Enums\MemberRole;
use App\Enums\ProgressStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\Project;
use App\Models\User;
use App\Services\ChargeService;
use App\Support\Charge\Periode;
use Database\Seeders\MpmReferentialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class ChargeServiceTest extends TestCase
{
    use RefreshDatabase;

    private Periode $mois;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MpmReferentialSeeder::class);
        $this->mois = Periode::mois(Date::now());
    }

    private function projet(): Project
    {
        return Project::factory()->create([
            'statut' => ProjectStatus::EnCours,
            'date_debut' => Date::now()->subMonths(3),
            'date_fin_initiale' => Date::now()->addMonths(3),
        ]);
    }

    private function affecter(Project $projet, User $user, MemberRole $role, int $allocation = 100): void
    {
        $projet->members()->create([
            'user_id' => $user->id,
            'role' => $role->value,
            'allocation_pct' => $allocation,
        ]);
    }

    public function test_le_poids_du_role_evite_les_sponsors_a_600_pourcent(): void
    {
        $sponsor = User::factory()->create();

        foreach (range(1, 6) as $ignored) {
            $this->affecter($this->projet(), $sponsor, MemberRole::Sponsor);
        }

        $charge = app(ChargeService::class)->pourCollaborateur($sponsor, $this->mois);

        // Six projets à 100 % d'allocation, mais un sponsor ne pèse que 5 %.
        $this->assertSame(6, $charge->nombreProjets());
        $this->assertEqualsWithDelta(30.0, $charge->total(), 0.5);
        $this->assertFalse($charge->enSurcharge());
    }

    public function test_un_developpeur_a_plein_temps_sature_son_plan(): void
    {
        $dev = User::factory()->create();

        $this->affecter($this->projet(), $dev, MemberRole::Developpeur);
        $this->affecter($this->projet(), $dev, MemberRole::Developpeur);

        $charge = app(ChargeService::class)->pourCollaborateur($dev, $this->mois);

        $this->assertEqualsWithDelta(160.0, $charge->total(), 0.5);
        $this->assertTrue($charge->enSurcharge());
    }

    public function test_une_affectation_hors_periode_ne_pese_pas(): void
    {
        $dev = User::factory()->create();
        $projet = $this->projet();

        $projet->members()->create([
            'user_id' => $dev->id,
            'role' => MemberRole::Developpeur->value,
            'allocation_pct' => 100,
            'date_debut' => Date::now()->addMonths(4),
            'date_fin' => Date::now()->addMonths(6),
        ]);

        $this->assertSame(0.0, app(ChargeService::class)->pourCollaborateur($dev, $this->mois)->total());
    }

    public function test_une_tache_confiee_fait_apparaitre_un_non_affecte(): void
    {
        $projet = $this->projet();
        $lead = User::factory()->create();
        $equipier = User::factory()->create();

        $this->affecter($projet, $lead, MemberRole::ReferentTechnique);

        $projet->tasks()->create([
            'libelle' => 'Développer le connecteur',
            'assignee_id' => $equipier->id,
            'delegue_par_id' => $lead->id,
            'date_echeance' => Date::now()->addDays(3),
            'charge_jours' => 5,
            'statut' => ProgressStatus::EnCours,
        ]);

        $charge = app(ChargeService::class)->pourCollaborateur($equipier, $this->mois);

        $this->assertSame(1, $charge->nombreProjets());
        $this->assertGreaterThan(0, $charge->total());
        // Aucune affectation nominale : la charge vient entièrement de la délégation.
        $this->assertSame(0.0, $charge->planifiee());
        $this->assertGreaterThan(0, $charge->deleguee());
    }

    public function test_les_taches_ne_sont_pas_comptees_deux_fois_pour_un_affecte(): void
    {
        $projet = $this->projet();
        $dev = User::factory()->create();

        $this->affecter($projet, $dev, MemberRole::Developpeur);

        $projet->tasks()->create([
            'libelle' => 'Une petite tâche',
            'assignee_id' => $dev->id,
            'date_echeance' => Date::now()->addDays(2),
            'charge_jours' => 1,
            'statut' => ProgressStatus::EnCours,
        ]);

        $charge = app(ChargeService::class)->pourCollaborateur($dev, $this->mois);

        // L'affectation (80 %) l'emporte sur la tâche : on retient le maximum.
        $this->assertEqualsWithDelta(80.0, $charge->total(), 0.5);
        $this->assertSame(1, $charge->nombreProjets());
    }

    public function test_un_projet_suspendu_ne_consomme_pas_de_charge(): void
    {
        $dev = User::factory()->create();
        $projet = $this->projet();

        $this->affecter($projet, $dev, MemberRole::Developpeur);
        $projet->update(['statut' => ProjectStatus::Suspendu]);

        $this->assertSame(0.0, app(ChargeService::class)->pourCollaborateur($dev, $this->mois)->total());
    }

    public function test_le_rattachement_par_delegation_rend_le_projet_visible(): void
    {
        $projet = $this->projet();
        $equipier = User::factory()->role(UserRole::Membre)->create();

        $this->assertSame(0, Project::visibleTo($equipier)->count());

        $tache = $projet->tasks()->create([
            'libelle' => 'Tâche confiée',
            'assignee_id' => $equipier->id,
            'date_echeance' => Date::now()->addDays(5),
            'statut' => ProgressStatus::NonDemarre,
        ]);

        $membre = app(ChargeService::class)->rattacherParDelegation($tache);

        $this->assertNotNull($membre);
        $this->assertSame(MemberRole::Contributeur, $membre->role);
        $this->assertSame(1, Project::visibleTo($equipier)->count());
        $this->assertTrue($equipier->can('contribute', $projet));
    }

    public function test_le_rattachement_ne_duplique_pas_un_membre_existant(): void
    {
        $projet = $this->projet();
        $dev = User::factory()->create();

        $this->affecter($projet, $dev, MemberRole::Developpeur);

        $tache = $projet->tasks()->create([
            'libelle' => 'Tâche du développeur',
            'assignee_id' => $dev->id,
            'date_echeance' => Date::now()->addDays(5),
            'statut' => ProgressStatus::NonDemarre,
        ]);

        $this->assertNull(app(ChargeService::class)->rattacherParDelegation($tache));
        $this->assertSame(1, $projet->members()->where('user_id', $dev->id)->count());
    }

    public function test_la_serie_couvre_les_periodes_demandees(): void
    {
        $dev = User::factory()->create();
        $this->affecter($this->projet(), $dev, MemberRole::Developpeur);

        $serie = app(ChargeService::class)->serie($dev, Periode::suite('mois', 6));

        $this->assertCount(6, $serie);
        $this->assertGreaterThan(0, $serie->first()['charge']->total());
    }
}
