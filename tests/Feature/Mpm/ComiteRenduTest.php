<?php

namespace Tests\Feature\Mpm;

use App\Models\User;
use Database\Seeders\MpmReferentialSeeder;
use Database\Seeders\PortefeuilleDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L'écran comité, rendu de bout en bout sur un portefeuille complet.
 *
 * Les autres tests du comité vérifient la séquence et son contenu à partir
 * d'un ou deux projets construits pour l'occasion. Celui-ci ne vérifie qu'une
 * chose, mais sur des données réalistes : que la page s'affiche. Une erreur
 * serveur sur cet écran ne se voit ni dans la séquence, ni dans l'export —
 * elle se voit en l'ouvrant.
 */
class ComiteRenduTest extends TestCase
{
    use RefreshDatabase;

    private User $direction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MpmReferentialSeeder::class);
        $this->seed(PortefeuilleDemoSeeder::class);

        $this->direction = User::query()
            ->firstWhere('email', 'sandrine.yapo14@gmail.com') ?? User::query()->firstOrFail();
    }

    public function test_lecran_saffiche_sur_une_semaine_pourvue_en_rapports(): void
    {
        $this->actingAs($this->direction)
            ->get(route('comite', ['semaine' => '2026-08-10']))
            ->assertOk()
            ->assertSee('Comité Projets');
    }

    /**
     * Le chemin ajouté par l'exhaustivité : aucun rapport rendu, mais des
     * projets actifs à présenter comme donnée manquante.
     */
    public function test_lecran_saffiche_sur_une_semaine_sans_aucun_rapport(): void
    {
        $this->actingAs($this->direction)
            ->get(route('comite', ['semaine' => '2026-09-14']))
            ->assertOk()
            ->assertSee('pas de flash report cette semaine');
    }

    public function test_lecran_saffiche_sans_semaine_precisee(): void
    {
        $this->actingAs($this->direction)
            ->get(route('comite'))
            ->assertOk();
    }

    /**
     * Un chef de projet ne voit qu'une partie du portefeuille : la séquence est
     * plus courte, et les diapositives doivent s'y adapter.
     */
    public function test_lecran_saffiche_pour_un_perimetre_restreint(): void
    {
        $chef = User::query()
            ->whereHas('projetsPilotes')
            ->whereRelation('profile', 'code', 'chef_projet')
            ->firstOrFail();

        $this->actingAs($chef)
            ->get(route('comite', ['semaine' => '2026-08-10']))
            ->assertOk();
    }
}
