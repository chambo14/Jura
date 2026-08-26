<?php

namespace Tests\Feature\Mpm;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\MpmReferentialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserPaginationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MpmReferentialSeeder::class);
    }

    private function direction(): User
    {
        return User::factory()->role(UserRole::Direction)->create(['name' => 'AAA Direction']);
    }

    public function test_la_liste_des_comptes_sarrete_a_dix_par_page(): void
    {
        $direction = $this->direction();
        User::factory()->count(24)->create();

        $page = Livewire::actingAs($direction)->test('pages::utilisateurs.onglets.comptes');

        $this->assertCount(10, $page->instance()->utilisateurs()->items());
        $this->assertSame(25, $page->instance()->utilisateurs()->total());
        $this->assertSame(3, $page->instance()->utilisateurs()->lastPage());
    }

    public function test_la_page_suivante_montre_les_comptes_suivants(): void
    {
        $direction = $this->direction();
        User::factory()->count(24)->create();

        $page = Livewire::actingAs($direction)->test('pages::utilisateurs.onglets.comptes');

        $premiere = collect($page->instance()->utilisateurs()->items())->pluck('id');

        $page->call('gotoPage', 2);
        $seconde = collect($page->instance()->utilisateurs()->items())->pluck('id');

        $this->assertCount(10, $seconde);
        $this->assertEmpty($premiere->intersect($seconde), 'Les deux pages se recouvrent.');
    }

    public function test_une_recherche_ramene_a_la_premiere_page(): void
    {
        $direction = $this->direction();
        User::factory()->count(24)->create();
        User::factory()->create(['name' => 'Zoé PARTICULIÈRE']);

        $page = Livewire::actingAs($direction)
            ->test('pages::utilisateurs.onglets.comptes')
            ->call('gotoPage', 3)
            ->set('recherche', 'PARTICULIÈRE');

        // Rester en page 3 d'une liste filtrée n'afficherait rien.
        $this->assertSame(1, $page->instance()->utilisateurs()->currentPage());
        $this->assertCount(1, $page->instance()->utilisateurs()->items());
    }
}
