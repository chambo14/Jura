<?php

namespace Tests\Feature\Mpm;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * La commande qui remplace la ligne de commande absente de l'hébergement.
 */
class MiseAJourTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_simulation_naplique_aucune_migration(): void
    {
        $this->retireLaTableDesCommentaires();

        $this->artisan('mpm:mise-a-jour', ['--pretend' => true])->assertSuccessful();

        // Une simulation qui migrerait quand même serait pire qu'inutile.
        $this->assertFalse(Schema::hasTable('task_comments'));
    }

    public function test_la_mise_a_jour_applique_les_migrations_en_attente(): void
    {
        $this->retireLaTableDesCommentaires();

        $this->artisan('mpm:mise-a-jour')->assertSuccessful();

        $this->assertTrue(Schema::hasTable('task_comments'));
    }

    /**
     * Remet la base dans l'état d'un serveur qui a reçu les fichiers mais dont
     * personne n'a joué les migrations : la table manque, et la ligne
     * correspondante est absente du journal des migrations.
     */
    private function retireLaTableDesCommentaires(): void
    {
        Schema::dropIfExists('task_comments');

        DB::table('migrations')
            ->where('migration', '2026_08_26_090300_create_task_comments_table')
            ->delete();

        $this->assertFalse(Schema::hasTable('task_comments'));
    }
}
