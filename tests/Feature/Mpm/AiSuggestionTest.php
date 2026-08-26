<?php

namespace Tests\Feature\Mpm;

use App\Enums\UserRole;
use App\Exceptions\SuggestionIndisponible;
use App\Models\Project;
use App\Models\User;
use App\Services\AiSuggestionService;
use Database\Seeders\MpmReferentialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiSuggestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MpmReferentialSeeder::class);

        config()->set('ia.active', true);
        config()->set('ia.cle', 'cle-de-test');
        config()->set('ia.url', 'https://api.anthropic.test/v1/messages');
    }

    private function projet(): Project
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create();

        return Project::factory()->create([
            'nom' => 'Interopérabilité monétique',
            'chef_projet_id' => $chef->id,
        ]);
    }

    private function reponse(string $texte): array
    {
        return ['content' => [['type' => 'text', 'text' => $texte]]];
    }

    public function test_sans_cle_lassistant_est_absent(): void
    {
        config()->set('ia.cle', null);

        $assistant = app(AiSuggestionService::class);

        $this->assertFalse($assistant->disponible());

        $this->expectException(SuggestionIndisponible::class);
        $assistant->pointDAttention($this->projet());
    }

    public function test_les_propositions_sont_renvoyees_telles_que_redigees(): void
    {
        Http::fake([
            '*' => Http::response($this->reponse('["Le jalon de recette glisse de deux semaines.", "La recette est décalée."]')),
        ]);

        $propositions = app(AiSuggestionService::class)->pointDAttention($this->projet());

        $this->assertSame(
            ['Le jalon de recette glisse de deux semaines.', 'La recette est décalée.'],
            $propositions,
        );
    }

    public function test_le_contexte_envoye_decrit_le_projet_et_ne_contient_pas_la_cle(): void
    {
        Http::fake(['*' => Http::response($this->reponse('["Une proposition."]'))]);

        app(AiSuggestionService::class)->pointDAttention($this->projet());

        Http::assertSent(function (Request $requete) {
            $corps = $requete->data();
            $message = $corps['messages'][0]['content'];

            return $requete->hasHeader('x-api-key', 'cle-de-test')
                && str_contains($message, 'Interopérabilité monétique')
                && ! str_contains($message, 'cle-de-test');
        });
    }

    public function test_leffort_de_raisonnement_accompagne_la_demande(): void
    {
        config()->set('ia.effort', 'low');
        Http::fake(['*' => Http::response($this->reponse('["Une proposition."]'))]);

        app(AiSuggestionService::class)->pointDAttention($this->projet());

        Http::assertSent(fn (Request $requete) => $requete->data()['output_config'] === ['effort' => 'low']);
    }

    public function test_un_modele_sans_effort_configure_nen_envoie_pas(): void
    {
        // Les générations antérieures rejettent ce paramètre : l'omettre doit
        // rester possible pour qui pointe IA_MODELE vers l'une d'elles.
        config()->set('ia.effort', '');
        Http::fake(['*' => Http::response($this->reponse('["Une proposition."]'))]);

        app(AiSuggestionService::class)->pointDAttention($this->projet());

        Http::assertSent(fn (Request $requete) => ! array_key_exists('output_config', $requete->data()));
    }

    public function test_une_reponse_hors_format_est_quand_meme_exploitee(): void
    {
        Http::fake(['*' => Http::response($this->reponse("Première proposition.\n\nSeconde proposition."))]);

        $propositions = app(AiSuggestionService::class)->pointDAttention($this->projet());

        $this->assertSame(['Première proposition.', 'Seconde proposition.'], $propositions);
    }

    public function test_un_service_en_erreur_ne_casse_pas_lecran(): void
    {
        Http::fake(['*' => Http::response(['error' => 'overloaded'], 529)]);

        $this->expectException(SuggestionIndisponible::class);

        app(AiSuggestionService::class)->pointDAttention($this->projet());
    }
}
