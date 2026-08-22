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
| **Fiche projet** | 6 onglets : Synthèse · Planning & délais · Ressources · Livrables · Tâches · Flash reports |
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

Cette machine n'a ni PHP, ni Composer, ni Node dans le `PATH`. Les chemins sont :

| Outil | Chemin |
|---|---|
| PHP 8.5 | `C:\php\php.exe` |
| Composer | `C:\Users\sandrine.yapo\composer\composer.phar` |
| Node 24 / npm | `C:\Users\sandrine.yapo\AppData\Local\nodejs-portable\node-v24.19.0-win-x64` |

Pour une session de travail, ajoutez-les au `PATH` :

```bash
$env:PATH = "C:\php;C:\Users\sandrine.yapo\AppData\Local\nodejs-portable\node-v24.19.0-win-x64;" + $env:PATH
```

Lancer le serveur :

```bash
php artisan serve --port=8000
```

Puis ouvrir <http://localhost:8000>.

### Avertissement `VCRUNTIME140.dll` au lancement de PHP

```
PHP Warning:  'C:\WINDOWS\SYSTEM32\VCRUNTIME140.dll' 14.28 is not compatible
with this PHP build linked with 14.44 in Unknown on line 0
```

PHP 8.5 pour Windows est compilé avec la chaîne d'outils Visual Studio 2022 et se lie à la
version **14.44** du runtime Visual C++. La `VCRUNTIME140.dll` installée dans
`C:\WINDOWS\SYSTEM32` est en **14.28**, livrée par un redistribuable plus ancien
(Visual Studio 2019). PHP démarre quand même, mais signale l'écart à chaque invocation —
`php artisan serve`, `php artisan test`, chaque commande Composer — et une extension
compilée avec le runtime récent peut échouer au chargement.

Ce n'est pas un défaut de l'application : rien dans le dépôt ne corrige cet avertissement,
il se règle sur le poste.

**Correctif — installer le redistribuable Visual C++ à jour.** Il est cumulatif : la
version 14.4x remplace la 14.28 et couvre toutes les applications déjà installées.

1. Télécharger <https://aka.ms/vs/17/release/vc_redist.x64.exe> — l'architecture doit être
   celle de PHP (`x64` pour un build 64 bits ; `php -i | findstr Architecture` le confirme).
2. L'exécuter (droits administrateur requis), puis redémarrer le terminal.
3. Vérifier :

   ```powershell
   (Get-Item C:\WINDOWS\SYSTEM32\VCRUNTIME140.dll).VersionInfo.ProductVersion
   C:\php\php.exe -v
   ```

   La version doit être ≥ 14.44 et `php -v` ne doit plus rien afficher avant le numéro de
   version de PHP.

**Sans droits administrateur.** Windows cherche une DLL d'abord dans le dossier de
l'exécutable, avant `System32`, et `VCRUNTIME140.dll` n'est pas une *KnownDLL*. Copier
`VCRUNTIME140.dll` et `VCRUNTIME140_1.dll` en 14.44 à côté de `C:\php\php.exe` suffit donc
à servir PHP seul, sans toucher au reste du poste. Les deux fichiers s'obtiennent depuis un
poste disposant déjà du redistribuable à jour, ou en extrayant `vc_redist.x64.exe` :

```powershell
.\vc_redist.x64.exe /layout C:\temp\vcredist
```

Le dossier produit contient les paquets d'où extraire les deux DLL. Copier les deux : la
14.44 dépend de `VCRUNTIME140_1.dll`, et n'en apporter qu'une remplace l'avertissement par
une erreur de chargement.

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
