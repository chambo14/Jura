<?php

/**
 * Mise à jour du site sans ligne de commande.
 *
 * L'hébergement mutualisé n'offre ni SSH ni Composer : les fichiers y arrivent
 * par dépôt d'archive, et rien n'exécute ensuite `php artisan migrate`. La base
 * reste donc en arrière, et les caches de configuration et de routes continuent
 * de servir l'ancienne version — au point qu'une route ajoutée par la mise à
 * jour n'existe pas encore pour le serveur. C'est pourquoi ce point d'entrée
 * est un fichier posé dans public/ et non une route de l'application : il est
 * le seul à rester joignable quand le cache des routes est périmé.
 *
 * Usage :
 *   1. renseigner MPM_UPDATE_TOKEN dans le .env du serveur (32 caractères ou
 *      plus, tirés au hasard) ;
 *   2. ouvrir https://…/mise-a-jour.php?jeton=LE_JETON — la page affiche les
 *      migrations en attente, sans rien appliquer ;
 *   3. relancer avec &appliquer=1 pour les appliquer et reconstruire les caches.
 *
 * Sans jeton dans le .env, la page répond 404 : la fonction est absente tant
 * qu'elle n'a pas été armée sciemment. Une fois la mise à jour faite, vider
 * MPM_UPDATE_TOKEN referme la porte.
 */

use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Log;

define('LARAVEL_START', microtime(true));

require __DIR__.'/../vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

// La référence au noyau est gardée : `route:cache` reconstruit une application
// neuve et rebranche les façades sur elle, si bien qu'un `Artisan::output()`
// interrogerait ensuite un autre conteneur — et ne rendrait rien.
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

// Quand la configuration est en cache, Laravel saute la lecture du .env : on la
// force ici, sans écraser ce qui serait déjà chargé, pour disposer du jeton.
Dotenv::createImmutable(
    dirname($app->environmentFilePath()),
    basename($app->environmentFilePath()),
)->safeLoad();

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

/**
 * Le jeton est lu directement dans le .env, jamais via config() : quand la
 * configuration est en cache — le cas en production, et justement ce que cette
 * page vient corriger — un fichier de configuration ajouté par la mise à jour
 * n'y figure pas encore.
 */
$attendu = (string) ($_ENV['MPM_UPDATE_TOKEN'] ?? $_SERVER['MPM_UPDATE_TOKEN'] ?? getenv('MPM_UPDATE_TOKEN') ?: '');
$fourni = (string) ($_GET['jeton'] ?? '');

// Un jeton court se devine : on préfère refuser que protéger pour de faux.
if (strlen($attendu) < 32 || ! hash_equals($attendu, $fourni)) {
    Log::warning('Tentative de mise à jour refusée.', [
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '?',
        'motif' => strlen($attendu) < 32 ? 'jeton absent ou trop court dans le .env' : 'jeton fourni incorrect',
    ]);

    http_response_code(404);
    exit("404\n");
}

$appliquer = ($_GET['appliquer'] ?? '') === '1';

Log::info('Mise à jour du site déclenchée depuis le web.', [
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '?',
    'mode' => $appliquer ? 'application' : 'simulation',
]);

// Reconstruire les caches prend quelques secondes ; migrer peut en prendre plus.
set_time_limit(300);

try {
    $code = $kernel->call('mpm:mise-a-jour', $appliquer ? [] : ['--pretend' => true]);

    echo $kernel->output();

    if (! $appliquer) {
        echo "\n";
        echo "Rien n'a été appliqué. Pour appliquer réellement, rechargez cette page\n";
        echo "en ajoutant &appliquer=1 à l'adresse.\n";
    }

    if ($code !== 0) {
        http_response_code(500);
    }
} catch (Throwable $e) {
    Log::error('Mise à jour du site en échec.', ['exception' => $e]);

    http_response_code(500);

    echo "La mise à jour a échoué :\n\n".$e->getMessage()."\n";
    echo "\nLe détail est dans storage/logs.\n";
}
