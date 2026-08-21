<?php

namespace Tests\Feature\Mpm;

use App\Enums\ProjectCategory;
use App\Models\Project;
use Database\Seeders\MpmReferentialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectComiteOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MpmReferentialSeeder::class);
    }

    /**
     * Le tri SQL des rubriques est écrit en dur dans Project ; ce test garantit
     * qu'il reste aligné sur ProjectCategory::rang() en cas d'évolution du sommaire.
     */
    public function test_le_tri_sql_couvre_toutes_les_rubriques_dans_le_bon_ordre(): void
    {
        foreach (ProjectCategory::cases() as $categorie) {
            Project::factory()->create([
                'categorie' => $categorie,
                'ordre_comite' => 1,
                'nom' => 'Projet '.$categorie->value,
            ]);
        }

        $attendu = collect(ProjectCategory::ordonnees())->map(fn ($c) => $c->value)->all();
        $obtenu = Project::ordreComite()->pluck('categorie')->map(fn ($c) => $c->value)->all();

        $this->assertSame($attendu, $obtenu);
    }

    public function test_les_projets_dune_rubrique_suivent_leur_ordre_de_passage(): void
    {
        foreach ([3 => 'Troisième', 1 => 'Premier', 2 => 'Deuxième'] as $rang => $nom) {
            Project::factory()->create([
                'categorie' => ProjectCategory::Monetique,
                'ordre_comite' => $rang,
                'nom' => $nom,
            ]);
        }

        $this->assertSame(
            ['Premier', 'Deuxième', 'Troisième'],
            Project::ordreComite()->pluck('nom')->all(),
        );
    }

    public function test_a_rang_egal_le_classement_est_alphabetique(): void
    {
        foreach (['Zeta', 'Alpha', 'Mu'] as $nom) {
            Project::factory()->create([
                'categorie' => ProjectCategory::Autres,
                'ordre_comite' => 0,
                'nom' => $nom,
            ]);
        }

        $this->assertSame(
            ['Alpha', 'Mu', 'Zeta'],
            Project::ordreComite()->pluck('nom')->all(),
        );
    }
}
