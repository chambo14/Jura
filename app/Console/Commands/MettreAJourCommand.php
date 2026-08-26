<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;

/**
 * Met le site à jour après un dépôt de fichiers.
 *
 * Envoyer les fichiers ne suffit pas : la base ne se met pas à niveau toute
 * seule, et un serveur de production garde en cache sa configuration, ses
 * routes et ses vues — tant qu'ils ne sont pas reconstruits, il continue de
 * servir l'ancienne version, y compris les routes qui n'existent plus et sans
 * voir celles qui viennent d'arriver.
 *
 * Cette commande enchaîne donc, dans l'ordre qui compte :
 *   1. purge des caches, sans quoi les nouveaux fichiers de configuration
 *      resteraient invisibles ;
 *   2. migrations en attente ;
 *   3. reconstruction des caches, mais seulement en production — en
 *      développement, un cache de configuration est un piège.
 *
 * Sur un hébergement sans ligne de commande, elle est déclenchée par
 * `public/mise-a-jour.php`.
 */
class MettreAJourCommand extends Command
{
    protected $signature = 'mpm:mise-a-jour
        {--pretend : Affiche les migrations en attente sans rien appliquer}';

    protected $description = 'Applique les migrations en attente et reconstruit les caches après un dépôt de fichiers';

    public function handle(): int
    {
        $this->components->info('Purge des caches');
        $this->call('optimize:clear');

        if ($this->option('pretend')) {
            $this->components->info('Migrations en attente (aucune ne sera appliquée)');
            $this->call('migrate:status');
        } else {
            $this->components->info('Migrations');
            $code = $this->call('migrate', ['--force' => true]);

            if ($code !== self::SUCCESS) {
                $this->components->error('Les migrations ont échoué : les caches ne sont pas reconstruits.');

                return self::FAILURE;
            }
        }

        // En développement, mettre la configuration en cache masque toute
        // modification du .env : on s'en garde.
        if (app()->isProduction()) {
            $this->components->info('Reconstruction des caches');
            $this->call('config:cache');
            $this->call('route:cache');
            $this->call('view:cache');

            $this->verifierLesRoutesLivewire();
        }

        $this->newLine();
        $this->components->info($this->option('pretend') ? 'Rien n’a été appliqué.' : 'Site à jour.');

        return self::SUCCESS;
    }

    /**
     * Livewire dérive l'adresse de ses points d'entrée — script, mise à jour,
     * téléversement — d'une empreinte de `APP_KEY`. Si la table des routes est
     * figée avec une empreinte et que les pages en réclament une autre, le
     * script répond 404 : les pages s'affichent, et plus aucun bouton ne
     * réagit. La panne est muette côté serveur, d'où cette vérification.
     *
     * Un cache de routes incohérent vaut moins qu'une route résolue à chaud :
     * on le retire plutôt que de laisser l'application inerte.
     */
    private function verifierLesRoutesLivewire(): void
    {
        if (! class_exists(EndpointResolver::class)) {
            return;
        }

        $prefixe = ltrim(EndpointResolver::prefix(), '/');
        $cache = $this->laravel->getCachedRoutesPath();

        if (! is_file($cache) || str_contains((string) file_get_contents($cache), $prefixe)) {
            return;
        }

        $this->call('route:clear');

        $this->components->warn(
            "Le cache des routes ne reconnaissait pas les adresses Livewire ({$prefixe}) : il a été retiré. "
            .'Les pages restent fonctionnelles ; relancez cette commande après avoir vérifié APP_KEY dans le .env.'
        );
    }
}
