#!/usr/bin/env bash
#
# Mise en service sur l'hébergement LWS.
#
# À lancer une seule fois, en SSH, depuis la racine du projet déjà envoyée sur
# le serveur — celle qui contient « artisan ». Le script refuse d'écraser une
# installation existante : il s'arrête si un .env est déjà là, ou si la base
# contient déjà des comptes.
#
#   bash deploy-lws.sh https://mpm.valeur-delivery-app.com
#
# Le nom et l'adresse du compte Direction peuvent être passés en arguments ;
# sinon ils sont demandés au clavier. Le mot de passe, lui, n'est jamais un
# argument : il finirait dans l'historique du shell. Il est saisi au clavier,
# ou lu dans MPM_ADMIN_PASSWORD pour une installation sans terminal.
#
set -euo pipefail

DOMAINE="${1:-}"
NOM="${2:-}"
EMAIL="${3:-}"

echec() { printf '\n\033[31mÉCHEC\033[0m %s\n' "$1" >&2; exit 1; }
etape() { printf '\n\033[34m▸\033[0m %s\n' "$1"; }

[ -n "$DOMAINE" ] || echec "Indiquez l'adresse du site : bash deploy-lws.sh https://mpm.valeur-delivery-app.com"
[ -f artisan ] || echec "Lancez le script depuis la racine du projet (le dossier qui contient « artisan »)."
[ -d vendor ] || echec "Le dossier vendor/ est absent. Lancez « composer install --no-dev --optimize-autoloader » sur votre poste, puis renvoyez-le."
[ -f public/build/manifest.json ] || echec "Les assets ne sont pas compilés. Lancez « npm run build » sur votre poste, puis renvoyez public/build/."
[ -f .env ] && echec "Un fichier .env existe déjà : installation probablement déjà faite. Supprimez-le sciemment si vous voulez recommencer."

etape "Écriture du .env de production"
cat > .env <<EOF
APP_NAME="Suivi Projets MPM"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=${DOMAINE}
APP_LOCALE=fr
APP_FALLBACK_LOCALE=fr
APP_FAKER_LOCALE=fr_FR

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=error

DB_CONNECTION=sqlite
DB_DATABASE=$(pwd)/database/database.sqlite

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync
CACHE_STORE=database

MAIL_MAILER=log
MAIL_FROM_ADDRESS="no-reply@${DOMAINE#https://}"
MAIL_FROM_NAME="\${APP_NAME}"
EOF
chmod 600 .env

etape "Génération de la clé d'application"
php artisan key:generate --force

etape "Préparation de la base et des droits d'écriture"
mkdir -p database storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
touch database/database.sqlite
chmod -R 775 storage bootstrap/cache database

etape "Migrations"
php artisan migrate --force

# Le référentiel MPM seul : phases, étapes, profils et types de livrables.
# Surtout pas db:seed complet, qui chargerait les comptes de démonstration
# dont le mot de passe est « password ».
etape "Chargement du référentiel MPM"
php artisan db:seed --class=MpmReferentialSeeder --force

COMPTES=$(php artisan tinker --execute='echo \App\Models\User::count();' 2>/dev/null | tr -dc '0-9')
if [ "${COMPTES:-0}" -gt 0 ]; then
    printf '\n%s compte(s) déjà présent(s) : création du compte Direction ignorée.\n' "$COMPTES"
else
    etape "Création du compte Direction"
    OPTIONS=()
    [ -n "$NOM" ] && OPTIONS+=(--nom="$NOM")
    [ -n "$EMAIL" ] && OPTIONS+=(--email="$EMAIL")
    php artisan mpm:creer-administrateur "${OPTIONS[@]+"${OPTIONS[@]}"}"
fi

etape "Mise en cache de la configuration"
php artisan config:cache
php artisan route:cache
php artisan view:cache

printf '\n\033[32mTerminé.\033[0m Ouvrez %s\n' "$DOMAINE"
printf 'Vérifiez ensuite que %s/.env renvoie bien une erreur 403 ou 404.\n' "$DOMAINE"
