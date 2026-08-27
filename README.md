# Suivi Projets MPM

Application de pilotage du portefeuille projets de la Direction des Projets & Organisation,
alignée sur la **Méthodologie Projet MEDIASOFT (MPM)**.

Elle permet aux chefs de projet de suivre les délais et les ressources de leurs projets,
de tenir à jour les livrables attendus par phase, et de produire le **flash report
hebdomadaire** présenté en comité.

## Ce que fait l'application

| Écran | Rôle |
|---|---|
| **Tableau de bord** | Portefeuille consolidé : avancement, échéances, santé de chaque projet, alertes critiques |
| **Projets** | Liste filtrable (type, statut, santé, chef de projet) et création d'un projet |
| **Fiche projet** | 8 onglets : Synthèse · Planning & délais · Ressources · Livrables · Tâches · Tableau · Documents · Flash reports |
| **Tableau des équipes** | Kanban du portefeuille : cartes déplaçables entre colonnes, filtres par équipe métier, discussion et pièces jointes par carte |
| **Flash reports** | Couverture hebdomadaire du portefeuille, préparation en masse des rapports manquants |
| **Plan de charge** | Charge par collaborateur, au mois ou à la semaine, avec fiche individuelle et histogramme prévisionnel |
| **Mode comité** | Présentation plein écran : diapositive de titre, intercalaires de rubrique, une diapositive par projet, navigation au clavier (← →) |
| **Référentiel MPM** | Les 8 phases, les livrables types par phase, les bandeaux d'avancement, la gouvernance |

### Suivi des délais

- Les **8 phases MPM** (Opportunité → Clôture) sont instanciées sur chaque projet avec
  dates prévues et réelles.
- Les **jalons** portent une date initiale, ce qui rend le glissement mesurable.
- Un **diagramme de Gantt** superpose barre prévue et barre réelle, avec repère « aujourd'hui ».
- Les **alertes de dérive** sont calculées automatiquement (voir `app/Services/ProjectHealthService.php`) :
  glissement de la date de fin, échéance dépassée, avancement en retard sur le calendrier,
  avancement déclaré incohérent avec le taux de réalisation, jalons / tâches / livrables obligatoires en retard.
  Chaque projet est classé **Sous contrôle · Sous surveillance · En alerte**.

### Livrables

Les livrables types du référentiel MPM sont déroulés automatiquement à la création du projet,
rattachés à leur phase et à leur entité responsable. Un livrable restreint à certains types de
projet (ex. *Plan de migration des données*) n'est créé que là où il s'applique.
`Recharger le référentiel MPM` rattrape les livrables ajoutés au référentiel après coup.

### Tableau des équipes

Un **kanban** dresse le travail en cours, au niveau d'un projet (onglet *Tableau*) ou du
portefeuille entier (menu *Tableau des équipes*). Les colonnes sont les statuts déjà
utilisés partout ailleurs : **Non démarré · En cours · Bloqué · Terminé**. Déplacer une
carte change le statut de la tâche — et donc, dans le même mouvement, le plan de charge,
la santé du projet et le flash report. Il n'y a pas deux vérités.

- La carte se lâche **sur une colonne** (elle passe en fin de file) ou **sur une autre
  carte** (elle se glisse devant elle) : c'est ce qui fixe l'ordre de passage.
- Une carte lâchée dans *Terminé* est datée du jour ; ressortie de *Terminé*, elle perd
  sa date de réalisation.
- Chaque carte porte une **priorité**, une **discussion** et ses **pièces jointes**.
  Commenter est ouvert à qui voit le projet — un sponsor peut répondre sans avoir le
  droit de modifier la carte.
- Le tableau se lit d'un bloc, ou **une bande par équipe métier** (case *Par équipe*).
  L'équipe d'un collaborateur se renseigne dans l'écran **Utilisateurs**.
- Les cartes annulées sont hors tableau ; la case *Annulées* les fait réapparaître pour
  retrouver un travail abandonné.

### Documents

Des fichiers se déposent sur un **projet**, un **livrable** ou une **tâche** — cahier des
charges, PV de recette, capture d'écran, planning. L'onglet *Documents* d'un projet
rassemble tout ce qui lui est rattaché, quel que soit le point de dépôt.

Les fichiers ne sont **jamais servis depuis un dossier public** : ils sont rangés sur un
disque privé sous un nom aléatoire, et chaque téléchargement repasse par le contrôle
d'accès du projet. Le nom d'origine est conservé pour l'affichage et restitué au
téléchargement. Qui voit le projet télécharge ; qui y contribue dépose et retire, chacun
pouvant retirer ce qu'il a lui-même déposé.

Taille et formats acceptés se règlent dans `config/documents.php` (20 Mo par fichier et
les formats bureautiques, images et archives, par défaut).

### Assistant de rédaction

Trois champs peuvent être **proposés plutôt que saisis de zéro** : la synthèse d'un flash
report, un point d'attention, la description d'une carte. L'assistant part de ce qui est
déjà en base — avancement, dérives détectées, activités de la semaine — et propose des
formulations que l'on reprend d'un clic, puis corrige.

Rien n'est enregistré par l'assistant : le texte retenu atterrit dans le champ, et c'est
l'enregistrement habituel de l'écran qui le valide. La consigne lui interdit d'inventer
un chiffre, une date ou un nom absent du contexte — la relecture reste due.

**Sans clé d'API renseignée, la fonction n'apparaît pas** et l'application fonctionne à
l'identique. Pour l'activer, créer une clé depuis la console Claude — *Settings → API keys*,
<https://platform.claude.com/settings/keys> — et la renseigner dans `ANTHROPIC_API_KEY`.

Deux points à connaître au moment de la créer. L'**expiration se choisit à la création** et
ne se modifie plus ensuite ; une clé expirée fait répondre 401 et il faut en créer une autre.
Et la facturation est **à l'usage** : chaque suggestion consomme le contexte du projet en
entrée et quelques phrases en sortie, soit une fraction de centime par clic.

Le modèle, l'effort de raisonnement et les délais se règlent dans `config/ia.php`.

### Flash report

Le rapport d'une semaine reprend la structure des slides existantes : **PILOTAGE**,
**PLANNING & AVANCEMENT**, **PHASE ACTUELLE DU PROJET**, puis les trois rubriques
*activités réalisées* / *activités à réaliser* / *points d'attention & alertes*.

Il est **pré-rempli** : les tâches clôturées pendant la semaine alimentent les activités
réalisées, celles échéant la semaine suivante les activités à réaliser, et les dérives
détectées les points d'attention. Le chef de projet ajuste, ajoute ses propres points
d'attention et sa synthèse, puis publie — la publication **fige les chiffres**, pour que
le rapport reste fidèle a posteriori.

Chaque diapositive reprend aussi l'**équipe projet** complète : chef de projet, back-up,
sponsor, référent technique, testeurs et tout autre intervenant affecté, avec son taux
d'allocation.

### Plan de charge

La charge d'un collaborateur combine deux lectures, pour une période donnée :

- **l'affectation nominale**, pondérée par le poids du rôle — un sponsor consomme 5 % d'un
  temps plein, un chef de projet 40 %, un développeur 80 % — et ramenée au prorata de la
  période effectivement couverte par l'affectation ;
- **les tâches confiées**, converties en pourcentage à partir de leur charge en jours.

Sur un même projet, **la plus élevée des deux est retenue**. Cela évite de compter deux fois
le même travail, tout en faisant apparaître les tâches déléguées à quelqu'un qui n'a aucune
affectation nominale.

**La délégation est prise en charge explicitement.** Quand un référent technique confie une
tâche à un membre de son équipe extérieur au projet, celui-ci est automatiquement rattaché
comme *Contributeur* : il voit le projet, peut mettre à jour sa tâche, et sa charge apparaît
dans le plan. La tâche conserve la trace de qui l'a déléguée.

Les projets **suspendus ne consomment pas de charge**.

### Ordre de passage au comité

Les projets sont classés selon le sommaire des dossiers existants :

| Rubrique | Contenu |
|---|---|
| **01 — Projets monétiques** | Interopérabilité, paiement instantané, TPE, wallets |
| **02 — Projets banques digitales** | Banque à distance, applications mobiles, WhatsApp banking |
| **03 — Projets avec réception de PV de recette** | Projets en attente ou en suite de recette |
| **04 — Autres types de projets** | Développements internes et clients hors périmètre monétique |

La rubrique et le rang de passage se règlent dans le formulaire projet ; le mode comité
insère automatiquement un intercalaire avant chaque rubrique.

## Rôles

| Rôle | Périmètre |
|---|---|
| **Direction des Projets** | Voit tout le portefeuille, crée et modifie tout projet, dépublie un rapport |
| **Chef de projet** | Crée des projets ; modifie le cadrage de ses projets et de ceux où il est back-up |
| **Membre d'équipe** | Voit les projets où il est affecté ; met à jour tâches et livrables, pas le cadrage |
| **Sponsor** | Consultation seule des projets qu'il sponsorise |

Ces quatre profils sont livrés avec l'application, mais **ils ne sont pas figés** :
l'écran **Profils** permet à la Direction d'ajuster leurs droits et d'en créer de
nouveaux. Un profil est un jeu de six droits nommés :

| Droit | Ce qu'il autorise |
|---|---|
| Voir tout le portefeuille | Accéder à tous les projets, et non aux seuls projets d'affectation |
| Créer des projets | Ouvrir un projet et dérouler le référentiel MPM |
| Piloter un projet | Modifier le cadrage : dates, périmètre, équipe, jalons, phases |
| Contribuer au quotidien | Mettre à jour les tâches et le statut des livrables |
| Rédiger les flash reports | Préparer, modifier et publier le rapport hebdomadaire |
| Administrer comptes et profils | Créer les comptes, attribuer les profils, définir leurs droits |

Deux garde-fous : les quatre profils MPM ne sont pas supprimables (leurs droits restent
modifiables), et le dernier profil administrateur encore attribué ne peut pas perdre ce
droit — sinon plus personne ne pourrait administrer l'application.

**Piloter un projet demande deux conditions** : un profil qui porte le droit de pilotage,
*et* un rattachement au projet comme chef de projet ou back-up. Un collaborateur désigné
back-up mais dont le profil n'accorde pas le pilotage peut contribuer, pas modifier le
cadrage.

**L'inscription publique est fermée.** L'application s'ouvre directement sur l'écran de
connexion ; les comptes et les profils sont créés par la Direction des Projets depuis
les écrans **Utilisateurs** et **Profils**. À la création d'un compte, un mot de passe
provisoire est généré et affiché une seule fois, à communiquer au collaborateur.

Un compte n'est jamais supprimé — il est **désactivé**, afin de préserver l'historique des
projets et des flash reports auxquels il est rattaché. Un administrateur ne peut pas se
désactiver lui-même.

## Démarrer

### Prérequis

| Outil | Version minimale | Pourquoi |
|---|---|---|
| PHP | **8.4.1** | Laravel 13 demande 8.3, mais les composants Symfony 8 figés dans `composer.lock` exigent 8.4.1 |
| Composer | 2.x | |
| Node / npm | **22.12** | requis par Vite 8 |

Les extensions suivantes doivent être actives. Sur Windows elles sont commentées
par défaut dans `php.ini` :

```ini
extension_dir = "C:\php\ext"
extension=curl
extension=fileinfo
extension=mbstring
extension=openssl
extension=pdo_sqlite
extension=sqlite3
extension=zip
```

Sans `openssl`, Composer refuse tout téléchargement — il échoue sur
`The openssl extension is required for SSL/TLS protection`. Sans `pdo_sqlite`
ni `sqlite3`, la base ne s'ouvre pas.

Vérifiez que c'est bien ce PHP qui répond : un autre PHP présent plus tôt dans
le `PATH` est la cause la plus fréquente d'un `composer install` qui échoue sur
la version.

```powershell
php -v
php --ini    # « Loaded Configuration File » indique le php.ini réellement lu
```

### Chemins de la machine de développement

Ces chemins sont propres à un poste et n'ont pas à être repris tels quels :

| Outil | Chemin |
|---|---|
| PHP | `C:\php\php.exe` |
| Composer | `C:\Users\sandrine.yapo\composer\composer.phar` |
| Node / npm | `C:\Users\sandrine.yapo\AppData\Local\nodejs-portable\node-v24.19.0-win-x64` |

Pour une session de travail, ajoutez-les au `PATH` :

```powershell
$env:PATH = "C:\php;C:\Users\sandrine.yapo\AppData\Local\nodejs-portable\node-v24.19.0-win-x64;" + $env:PATH
```

### Installation

```powershell
composer install
copy .env.example .env
php artisan key:generate
php -r "touch('database/database.sqlite');"
php artisan migrate --seed
npm install
npm run build
```

Le fichier SQLite n'est pas versionné : il faut le créer avant la première
migration. `composer setup` enchaîne l'essentiel, mais ne crée ni la base ni
le jeu de démonstration.

### Réglages facultatifs

Deux fonctions se règlent dans `.env`, l'application marchant sans elles :

| Clé | Effet |
|---|---|
| `DOCUMENTS_DISK` | Disque de stockage des pièces jointes (`local` par défaut, privé) |
| `DOCUMENTS_MAX_KO` | Taille maximale d'un fichier, en Ko — à garder sous `upload_max_filesize` et `post_max_size` de `php.ini` |
| `ANTHROPIC_API_KEY` | Active l'assistant de rédaction ; vide, les boutons « Proposer une rédaction » n'apparaissent pas |
| `MPM_UPDATE_TOKEN` | Arme la page de mise à jour sans ligne de commande (voir plus bas) ; vide, elle répond 404 |
| `IA_MODELE` | Modèle interrogé par l'assistant (`claude-opus-5` par défaut) |
| `IA_EFFORT` | Effort de raisonnement : `low` par défaut, suffisant pour reformuler. À vider si `IA_MODELE` pointe vers une génération antérieure, qui rejette ce paramètre |

### Lancer le serveur

```powershell
php artisan serve --port=8000
```

Puis ouvrir <http://localhost:8000>.

### Réinitialiser la base avec le jeu de démonstration

```bash
php artisan migrate:fresh --seed
```

Le jeu de démonstration reconstitue le comité projets du **10 au 14 août 2026** —
15 projets repris des dossiers de KEVIN ZAGO, BEN CISSÉ, SERGE GOUETY et SIAKA DONAFANNY,
avec leurs équipes, jalons, activités de la semaine, points d'attention et synthèses.

| Chef de projet | Projets |
|---|---|
| Kevin ZAGO | OBA Mobile, VEROPAY / Coris Bank |
| Kouamé ANSELME | BBCI e-Zikash |
| Ben CISSÉ N'GODJIGUI | BDM Kunkan, Application mobile BAM, TURAPAY |
| Serge GOUETY | Versus Net Pro, BHLINK PI, BHLINK B2W/W2B, PI SPI Versus, ALTERNATIS |
| Siaka KONÉ DONAFANNY | BRB-IPS Lot 1, BRB-IPS Lot 2, SGIFS, WhatsApp Banking BMS |

Tous les comptes de démonstration utilisent le mot de passe `password`.
Le compte Direction est `sandrine.yapo14@gmail.com`.

> Les comptes de démonstration sont à supprimer avant toute mise en service réelle.

### Recompiler les assets

```bash
npm run build
```

**Le résultat est versionné.** `public/build/` est suivi par Git, contrairement à
l'usage. La raison est concrète : le poste de la Direction des Projets n'a pas les
droits d'installer npm, et l'hébergement mutualisé n'a ni SSH ni Node. Si les feuilles
de style compilées ne voyageaient pas dans le dépôt, une nouvelle classe Tailwind
introduite par un changement de vue ne pourrait être compilée nulle part, et l'écran
s'afficherait sans style.

En pratique : **qui modifie une vue recompile et livre `public/build/` dans le même
commit.** Un `git pull` suffit alors à toute personne qui n'a pas la chaîne Node.

Premier `git pull` après ce changement : si un `public/build/` non suivi traîne déjà sur
le poste, Git refuse de l'écraser. Le supprimer avant de tirer.

```powershell
Remove-Item public\build -Recurse -Force
git pull origin <branche>
```

## Mettre à jour le site en ligne

Déposer les fichiers — par FTP, par archive dépliée depuis le gestionnaire de fichiers,
peu importe — **ne met pas le site à jour**. Il reste deux choses que seule une commande
peut faire, et que l'hébergement mutualisé n'offre pas :

1. **jouer les migrations** : les nouvelles tables et colonnes n'apparaissent pas toutes
   seules, et les écrans qui en dépendent tombent en erreur tant qu'elles manquent ;
2. **reconstruire les caches** : un serveur de production garde en cache sa
   configuration, ses routes et ses vues. Tant qu'ils ne sont pas refaits, il sert
   l'ancienne version — au point qu'une route ajoutée par la mise à jour n'existe pas
   encore pour lui.

### Préparer l'archive

L'hébergement reçoit le projet sous forme d'archive dépliée depuis le gestionnaire de
fichiers. Trois écueils, rencontrés en conditions réelles, méritent d'être connus avant
de la fabriquer.

**Ne pas embarquer `vendor/` quand `composer.lock` n'a pas changé.** Le serveur porte
déjà les bonnes dépendances : les renvoyer n'apporte rien, alourdit l'archive de 25 Mo
et, surtout, expose neuf mille fichiers PHP tiers à l'antivirus de l'hébergeur — dont les
heuristiques « téléchargeur PHP » se déclenchent sur des bibliothèques parfaitement
légitimes, et le dépôt est alors refusé en bloc. Vérifier d'un coup d'œil :

```powershell
git diff origin/main --name-only -- composer.json composer.lock   # vide = vendor inutile
```

**Fabriquer un `.tar.gz`, pas un `.zip`.** `Compress-Archive` de Windows PowerShell 5.1
écrit les chemins des sous-dossiers avec des antislashs (`app\Models\Project.php`), ce
que le format ZIP interdit. Déplié sur un serveur Linux, un tel fichier ne recrée pas
l'arborescence : il pose des milliers de fichiers dont le *nom* contient des antislashs.
Le format tar n'a pas cette ambiguïté.

**Sauvegarder la base par copie, jamais par renommage.** Un *Rename* laisse le projet
sans `database/database.sqlite` : le site tombe, et la mise à jour échoue au premier
accès à la base.

Sur le poste de développement, une fois `composer install --no-dev --optimize-autoloader`
passé — `npm run build` n'étant nécessaire que si les assets compilés du dépôt ne sont
pas à jour :

```powershell
$src = "C:\chemin\vers\Jura"
$tmp = "$env:TEMP\mpm-mise-en-ligne"
$tgz = "$env:USERPROFILE\Desktop\mpm.tar.gz"

if (Test-Path $tmp) { Remove-Item $tmp -Recurse -Force }
Copy-Item $src $tmp -Recurse

# Ce qui n'a rien à faire sur le serveur : sources de travail, fichiers du serveur
# lui-même (.env, base, storage), et caches locaux qui casseraient la production.
Remove-Item "$tmp\.git","$tmp\node_modules","$tmp\tests","$tmp\storage" -Recurse -Force -ErrorAction SilentlyContinue
Remove-Item "$tmp\.env" -Force -ErrorAction SilentlyContinue
Remove-Item "$tmp\database\*.sqlite*" -Force -ErrorAction SilentlyContinue
Remove-Item "$tmp\bootstrap\cache\*.php" -Force -ErrorAction SilentlyContinue
Remove-Item "$tmp\vendor" -Recurse -Force -ErrorAction SilentlyContinue   # si composer.lock n'a pas bougé

if (Test-Path $tgz) { Remove-Item $tgz -Force }
tar.exe -czf $tgz -C $tmp .
```

Puis contrôler l'archive avant de la déposer — c'est ce contrôle qui a rattrapé les
antislashs :

```powershell
$noms = (tar.exe -tzf $tgz) -replace '^\./',''
"public/mise-a-jour.php","public/build/manifest.json","artisan" |
    ForEach-Object { "{0,-32} {1}" -f $_, $(if ($noms -contains $_) { "OK" } else { "MANQUANT" }) }
```

Sur le serveur : sauvegarder la base (**Copy**), déplier l'archive dans le dossier qui
contient `artisan` en écrasant, supprimer l'archive, puis dérouler la mise à jour
ci-dessous.

### Avec une ligne de commande (SSH)

```bash
php artisan mpm:mise-a-jour            # purge, migre, reconstruit les caches
php artisan mpm:mise-a-jour --pretend  # montre les migrations en attente, n'applique rien
```

### Sans ligne de commande

`public/mise-a-jour.php` fait le même travail depuis un navigateur. C'est un fichier posé
dans `public/`, et non une route de l'application : il est le seul à rester joignable
quand le cache des routes est périmé.

1. **Tirer un jeton** — sur votre poste :

   ```bash
   php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
   ```

2. **L'inscrire dans le `.env` du serveur** :

   ```
   MPM_UPDATE_TOKEN=le-jeton-tiré
   ```

3. **Regarder d'abord** : ouvrir `https://…/mise-a-jour.php?jeton=LE_JETON`. La page liste
   les migrations en attente **sans rien appliquer**.

4. **Appliquer** : recharger la même adresse en ajoutant `&appliquer=1`. La page affiche
   le détail de ce qui a été migré et des caches reconstruits.

5. **Refermer** : vider `MPM_UPDATE_TOKEN` dans le `.env`. La page répond de nouveau 404.

Sans jeton dans le `.env`, ou avec un jeton de moins de 32 caractères, la page répond
**404** : la fonction n'existe pas tant qu'elle n'a pas été armée sciemment. Chaque
tentative, réussie ou refusée, est journalisée dans `storage/logs` avec l'adresse IP.

> Les fichiers déposés par les utilisateurs vivent dans `storage/app/`, exclu du transfert
> par le workflow de mise en ligne : une mise à jour ne les écrase pas. Ce dossier doit
> rester accessible en écriture sur le serveur.

## Qualité

```bash
php artisan test
```

```bash
vendor\bin\pint --parallel --test
```

```bash
vendor\bin\phpstan analyse --memory-limit=1G
```

## Organisation du code

```
app/
  Enums/            Vocabulaire métier (phases, statuts, rôles, santé, instances)
  Models/           Projets, phases, jalons, tâches, livrables, flash reports
  Policies/         Périmètre de visibilité et droits d'écriture
  Services/
    ProjectProvisioner    Déroule le référentiel MPM sur un projet
    ProjectHealthService  Calcule les dérives et la santé d'un projet
    FlashReportBuilder    Prépare, rafraîchit et publie le flash report
    GanttBuilder          Positionne phases et jalons pour le diagramme
    AttachmentService     Dépôt et retrait des pièces jointes sur disque privé
    AiSuggestionService   Propositions de rédaction (synthèse, point d'attention, tâche)
  Support/          Objets de valeur (alertes, santé, données du Gantt)
database/seeders/
  MpmReferentialSeeder    Les 8 phases, 35 livrables types, bandeaux par type de projet
  PortefeuilleDemoSeeder  Portefeuille de démonstration
resources/views/
  components/       Slide de flash report, Gantt, bandeau de phases, indicateurs
  pages/            Écrans Livewire
```

Le référentiel MPM vit dans `MpmReferentialSeeder` : il est **idempotent** et peut être
rejoué après une évolution de la méthodologie.
#   J u r a 
 
 