<?php

namespace Database\Seeders;

use App\Enums\DeliverableStatus;
use App\Enums\FlashItemType;
use App\Enums\MemberRole;
use App\Enums\ProgressStatus;
use App\Enums\ProjectCategory;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Profile;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkflowStep;
use App\Services\FlashReportBuilder;
use App\Services\ProjectProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Portefeuille du comité projets de la semaine du 10 au 14 août 2026, reconstitué
 * à partir des dossiers de KEVIN ZAGO, BEN CISSÉ, SERGE GOUETY et SIAKA DONAFANNY.
 *
 * Chaque projet est décrit de façon déclarative dans definitions() : pilotage, délais,
 * étape courante, activités de la semaine et points d'attention rédigés. Le flash report
 * hebdomadaire en est déduit puis complété par les points d'attention du chef de projet.
 *
 * Mot de passe commun à tous les comptes : « password ».
 */
class PortefeuilleDemoSeeder extends Seeder
{
    /**
     * Semaines couvertes par les flash reports générés, dans l'ordre chronologique.
     */
    private const SEMAINES = ['2026-08-10', '2026-08-17'];

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Client> */
    private array $clients = [];

    public function run(ProjectProvisioner $provisioner, FlashReportBuilder $builder): void
    {
        $this->users = $this->seedUsers();
        $this->clients = $this->seedClients();

        // Semaine 1 : création du portefeuille et premier flash report.
        $projets = [];

        foreach ($this->definitions() as $definition) {
            $projet = $this->creerProjet($definition, $provisioner);
            $projets[$definition['code']] = $projet;

            $this->redigerFlashReport($projet, $definition, $builder, Carbon::parse(self::SEMAINES[0]));
        }

        // Semaine 2 : le portefeuille évolue, un nouveau flash report est rédigé.
        foreach ($this->misesAJour() as $code => $maj) {
            if (! isset($projets[$code])) {
                continue;
            }

            $projet = $this->appliquerMiseAJour($projets[$code], $maj);

            $this->redigerFlashReport($projet, $maj, $builder, Carbon::parse(self::SEMAINES[1]));
        }
    }

    /**
     * @return array<string, User>
     */
    private function seedUsers(): array
    {
        // clé => [nom, email, rôle, poste, équipe métier]
        $definitions = [
            'sandrine' => ['Sandrine YAPO', 'sandrine.yapo14@gmail.com', UserRole::Direction, 'Direction des Projets & Organisation', 'Direction Projets'],

            'kevin' => ['Kevin ZAGO', 'kevin.zago@demo.local', UserRole::ChefProjet, 'Chef de projet', 'Direction Projets'],
            'anselme' => ['Kouamé ANSELME', 'kouame.anselme@demo.local', UserRole::ChefProjet, 'Chef de projet', 'Direction Projets'],
            'ben' => ['Ben CISSÉ N\'GODJIGUI', 'ben.cisse@demo.local', UserRole::ChefProjet, 'Chef de projet', 'Direction Projets'],
            'serge' => ['Serge GOUETY', 'serge.gouety@demo.local', UserRole::ChefProjet, 'Chef de projet', 'Direction Projets'],
            'siaka' => ['Siaka KONÉ DONAFANNY', 'siaka.donnafanny@demo.local', UserRole::ChefProjet, 'Chef de projet', 'Direction Projets'],

            'stephanie' => ['Stéphanie COULIBALY', 'stephanie.coulibaly@demo.local', UserRole::Sponsor, 'Sponsor', 'Métier'],
            'oumar' => ['Oumar KAMARA', 'oumar.kamara@demo.local', UserRole::Sponsor, 'Sponsor', 'Métier'],
            'flatene' => ['FLATENE KÉITA', 'flatene.keita@demo.local', UserRole::Sponsor, 'Sponsor', 'Métier'],
            'olivier' => ['Olivier N\'DA', 'olivier.nda@demo.local', UserRole::Sponsor, 'Sponsor', 'Métier'],
            'fofana' => ['Bandié FOFANA', 'bandie.fofana@demo.local', UserRole::Sponsor, 'Sponsor', 'Métier'],
            'sanogo' => ['Oumar SANOGO', 'oumar.sanogo@demo.local', UserRole::Sponsor, 'Sponsor', 'Métier'],

            'alex' => ['Alex BEDA', 'alex.beda@demo.local', UserRole::Membre, 'Référent technique', 'Monétique'],
            'bekouanKassi' => ['Bekouan KASSI', 'bekouan.kassi@demo.local', UserRole::Membre, 'Référent technique', 'Monétique'],
            'bekouanMartial' => ['Bekouan J. MARTIAL', 'bekouan.martial@demo.local', UserRole::Membre, 'Référent technique', 'Monétique'],
            'karim' => ['Karim KAMARA', 'karim.kamara@demo.local', UserRole::Membre, 'Référent technique', 'Monétique'],
            'yacouba' => ['Yacouba BAH', 'yacouba.bah@demo.local', UserRole::Membre, 'Référent technique', 'Monétique'],
            'arnaud' => ['Arnaud SAPIM', 'arnaud.sapim@demo.local', UserRole::Membre, 'Référent technique', 'Banque digitale'],
            'jeanpaul' => ['Jean-Paul SAPIM', 'jeanpaul.sapim@demo.local', UserRole::Membre, 'Référent technique', 'Banque digitale'],
            'lamine' => ['Lamine KOMOU', 'lamine.komou@demo.local', UserRole::Membre, 'Référent technique', 'Banque digitale'],
            'katina' => ['Katina OUATTARA', 'katina.ouattara@demo.local', UserRole::Membre, 'Référent technique', 'Banque digitale'],
            'franck' => ['Franck GNOGOURI', 'franck.gnogouri@demo.local', UserRole::Membre, 'Référent technique', 'Banque digitale'],

            'zie' => ['Zié COULIBALY', 'zie.coulibaly@demo.local', UserRole::Membre, 'Testeur', 'Recette & Qualité'],
            'gbadje' => ['GBADJÉ', 'gbadje@demo.local', UserRole::Membre, 'Testeur', 'Recette & Qualité'],
            'lacina' => ['Lacina KONATÉ', 'lacina.konate@demo.local', UserRole::Membre, 'Testeur', 'Recette & Qualité'],
            'jeanne' => ['Jeanne KWAH', 'jeanne.kwah@demo.local', UserRole::Membre, 'Testeuse', 'Recette & Qualité'],

            'michee' => ['Michée TEKI', 'michee.teki@demo.local', UserRole::Membre, 'Intégrateur', 'Intégration'],
            'ngoran' => ['Sandrine N\'GORAN', 'sandrine.ngoran@demo.local', UserRole::Membre, 'Chef de projet adjoint', 'Direction Projets'],
        ];

        // Les profils système sont semés par MpmReferentialSeeder.
        $profils = Profile::pluck('id', 'code');

        $users = [];

        foreach ($definitions as $cle => [$nom, $email, $role, $poste, $equipe]) {
            $users[$cle] = User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $nom,
                    'profile_id' => $profils[$role->value] ?? null,
                    'poste' => $poste,
                    // Le poste dit le métier en toutes lettres ; la fonction
                    // en est la forme structurée, qui décide des rôles
                    // proposés sur une fiche projet.
                    'fonction' => MemberRole::depuisUnPoste($poste),
                    'equipe' => $equipe,
                    'actif' => true,
                    'password' => 'password',
                    'email_verified_at' => now(),
                ],
            );
        }

        return $users;
    }

    /**
     * @return array<string, Client>
     */
    private function seedClients(): array
    {
        $definitions = [
            'bbci' => ['Banque Burundaise pour le Commerce et l\'Investissement', 'BBCI', 'Burundi'],
            'oba' => ['Orange Banque Africa', 'OBA', 'Côte d\'Ivoire'],
            'coris' => ['Coris Bank International', 'CORIS', 'Côte d\'Ivoire'],
            'bdm' => ['Banque de Développement du Mali', 'BDM', 'Mali'],
            'bms' => ['Banque Malienne de Solidarité', 'BMS SA', 'Mali'],
            'bam' => ['Bridge Asset Management', 'BAM', 'Côte d\'Ivoire'],
            'versus' => ['Versus Bank', 'VERSUS', 'Côte d\'Ivoire'],
            'bhci' => ['Banque de l\'Habitat de Côte d\'Ivoire', 'BHCI', 'Côte d\'Ivoire'],
            'bni' => ['Banque Nationale d\'Investissement', 'BNI', 'Côte d\'Ivoire'],
        ];

        $clients = [];

        foreach ($definitions as $cle => [$nom, $sigle, $pays]) {
            $clients[$cle] = Client::updateOrCreate(
                ['nom' => $nom],
                ['sigle' => $sigle, 'pays' => $pays, 'secteur' => 'Banque'],
            );
        }

        return $clients;
    }

    /**
     * Description déclarative de chaque projet du comité.
     *
     * Clés d'une définition :
     *   identité   : code, nom, description, client, type, categorie, ordre, pays
     *   délais     : debut, fin_initiale, fin_revisee, avancement, taux, lien
     *   pilotage   : cp, backup, sponsor, reftech, testeurs
     *   position   : etape (+ annotation), phase (phase MPM atteinte), livres (livrables validés jusqu'à)
     *   semaine    : realisees, a_realiser, attention, synthese
     *   calendrier : jalons
     *
     * @return array<int, array<string, mixed>>
     */
    private function definitions(): array
    {
        return [
            // ───────────────────────────── PROJETS MONÉTIQUES ─────────────────────────────
            [
                'code' => 'BRB-IPS-LOT1',
                'nom' => 'Interopérabilité BRB-IPS / BBCI — Lot 1 (Connecteur AIP)',
                'description' => 'Intégration des systèmes IPS et BBCI afin de permettre des paiements instantanés, irrévocables et disponibles 24h/24 et 7j/7 entre les différents acteurs du système financier (banques, établissements de paiement, mobile money), dans un cadre sécurisé, interopérable et conforme aux exigences réglementaires.',
                'client' => 'bbci', 'type' => ProjectType::Developpement,
                'categorie' => ProjectCategory::Monetique, 'ordre' => 1, 'pays' => 'Burundi',
                'debut' => '2026-03-09', 'fin_initiale' => '2026-06-05', 'fin_revisee' => '2026-08-23',
                'avancement' => 95, 'taux' => 90,
                'cp' => 'siaka', 'backup' => 'ngoran', 'sponsor' => 'stephanie', 'reftech' => 'katina',
                'testeurs' => [],
                'etape' => 'Tests fonctionnels', 'annotation' => 'tests E2E avec la BRB',
                'phase' => 'QUALIFICATION', 'livres' => 'REALISATION',
                'realisees' => [
                    ['Disponibilité de la connectivité permettant les tests d\'interopérabilité côté client', '2026-08-11', ProgressStatus::Termine],
                    ['Travaux d\'homologation — Projet AIP BRB (planning révisé au 23 août 2026)', '2026-08-14', ProgressStatus::EnCours],
                    ['Reprise des 17 blocs de tests directement avec la BRB (CAS, P2P, Return, QR dynamiques, QR hybrides, RTP de bout en bout, tests avec signature)', '2026-08-14', ProgressStatus::EnCours],
                ],
                'a_realiser' => [
                    ['Validation en présentiel avec l\'application mobile', '2026-08-25', ProgressStatus::NonDemarre],
                    ['Travaux d\'homologation en week-end pour rattraper le retard sur les tests AIP IPS', '2026-08-21', ProgressStatus::NonDemarre],
                ],
                'attention' => [
                    'La rencontre de prédémarrage s\'est tenue le 03/02/2026. Le projet est structuré en 9 modules (M1 à M9) sur 13 sprints hebdomadaires.',
                    'Les équipes techniques ont identifié une instabilité des serveurs et une coupure de fibre optique comme causes des difficultés récentes ; la connectivité est sous surveillance et une demande d\'assistance à la BRB est anticipée.',
                    'Le planning de fin a déjà été révisé deux fois (10 juillet, puis 31 juillet, puis 23 août 2026).',
                ],
                'synthese' => "Lot 1 à 95 % d'avancement. La reprise des 17 blocs de tests avec la BRB conditionne la validation en présentiel prévue le 25 août 2026. Des travaux de week-end sont programmés les 22 et 23 août pour tenir la date révisée.",
                'jalons' => [
                    ['Rencontre de prédémarrage', '2026-02-03', '2026-02-03', ProgressStatus::Termine, false, null],
                    ['Connectivité client disponible pour les tests', '2026-06-12', '2026-06-12', ProgressStatus::Termine, false, null],
                    ['Validation en présentiel de l\'application mobile', '2026-08-25', null, ProgressStatus::NonDemarre, true, null],
                    ['Fin des travaux d\'homologation AIP BRB', '2026-08-23', null, ProgressStatus::EnCours, true, null],
                ],
            ],
            [
                'code' => 'BRB-IPS-LOT2',
                'nom' => 'Interopérabilité BRB-IPS / BBCI — Lot 2 (Intégration Backend Participant)',
                'description' => 'Intégration des systèmes IPS et BBCI afin de permettre des paiements instantanés, irrévocables et disponibles 24h/24 et 7j/7 entre les différents acteurs du système financier, dans un cadre sécurisé, interopérable et conforme aux exigences réglementaires.',
                'client' => 'bbci', 'type' => ProjectType::Integration,
                'categorie' => ProjectCategory::Monetique, 'ordre' => 2, 'pays' => 'Burundi',
                'debut' => '2026-07-28', 'fin_initiale' => '2026-09-05', 'fin_revisee' => null,
                'avancement' => 30, 'taux' => 33,
                'cp' => 'siaka', 'backup' => 'sandrine', 'sponsor' => 'stephanie', 'reftech' => 'katina',
                'testeurs' => ['zie'],
                'etape' => 'Adaptation', 'annotation' => 'module 3 — application mobile',
                'phase' => 'REALISATION', 'livres' => 'CADRAGE',
                'realisees' => [
                    ['Validation du planning global', '2026-08-11', ProgressStatus::Termine],
                    ['Module 1 — Travaux d\'adaptation au SI (intégration sécurisée au système d\'information)', '2026-08-12', ProgressStatus::Termine],
                    ['Module 2 — Intégration backend PI / SI : interconnexion interbancaire et SI de la BBCI avec l\'IPS BRB', '2026-08-14', ProgressStatus::Termine],
                    ['Module 3 — Application mobile : exposition des API pour les canaux mobile et web', '2026-08-14', ProgressStatus::EnCours],
                ],
                'a_realiser' => [
                    ['Module 4 — Homologation BRB en présentiel sur l\'application mobile', '2026-08-25', ProgressStatus::NonDemarre],
                    ['Poursuivre le module 3 — exposition des API canaux', '2026-08-21', ProgressStatus::EnCours],
                ],
                'attention' => [
                    'La BRB fixe la date limite d\'intégration des participants à Burundi Pay au plus tard le 05 septembre 2026 : le jalon est contractuel et non négociable.',
                ],
                'synthese' => "Lot 2 démarré le 28 juillet, à 30 % d'avancement. Les modules 1 et 2 sont livrés ; le module 3 (application mobile) est en cours. L'échéance réglementaire du 05 septembre 2026 imposée par la BRB laisse peu de marge.",
                'jalons' => [
                    ['Validation du planning global', '2026-08-11', '2026-08-11', ProgressStatus::Termine, false, null],
                    ['Homologation BRB en présentiel (module 4)', '2026-08-25', null, ProgressStatus::NonDemarre, true, null],
                    ['Date limite BRB d\'intégration à Burundi Pay', '2026-09-05', null, ProgressStatus::NonDemarre, true, null],
                ],
            ],
            [
                'code' => 'BHLINK-PI',
                'nom' => 'BHLINK — PI (BCEAO)',
                'description' => "Ce projet vise à permettre aux clients de la BHCI d'effectuer, depuis BHLINK (mobile), des transactions instantanées en s'appuyant sur l'infrastructure de la BCEAO, conformément aux exigences réglementaires en vigueur.",
                'client' => 'bhci', 'type' => ProjectType::Integration,
                'categorie' => ProjectCategory::Monetique, 'ordre' => 3, 'pays' => 'Côte d\'Ivoire',
                'debut' => '2025-12-01', 'fin_initiale' => '2026-01-16', 'fin_revisee' => '2026-08-31',
                'avancement' => 83.33, 'taux' => 95,
                'lien' => 'https://groupemediasoftcom.sharepoint.com/:u:/s/ProjetsOrganisation/EUQ8eLuX1uBIkqnCAwRy5mUB7EcW-gQeSdkIyKmMR7_IdQ',
                'cp' => 'serge', 'backup' => null, 'sponsor' => 'stephanie', 'reftech' => 'karim',
                'testeurs' => ['zie'],
                'etape' => 'Pilote', 'annotation' => 'en cours',
                'phase' => 'EXECUTION', 'livres' => 'DEPLOIEMENT',
                'realisees' => [
                    ['Tests d\'acceptation utilisateur (UAT)', '2026-08-11', ProgressStatus::Termine],
                    ['Fiche de recette provisoire signée et reçue', '2026-08-12', ProgressStatus::Termine],
                    ['Déploiement en environnement de production', '2026-08-13', ProgressStatus::Termine],
                    ['Rédaction et envoi du guide utilisateur', '2026-08-13', ProgressStatus::Termine],
                    ['Pilote', '2026-08-14', ProgressStatus::EnCours],
                ],
                'a_realiser' => [
                    ['Obtenir la validation de la BCEAO', '2026-08-19', ProgressStatus::NonDemarre],
                    ['Signature du PV de recette définitif', '2026-08-20', ProgressStatus::NonDemarre],
                    ['Obtenir le GO de la BHCI', '2026-08-20', ProgressStatus::NonDemarre],
                    ['Généralisation et suivi post-généralisation', '2026-08-21', ProgressStatus::NonDemarre],
                ],
                'attention' => [
                    'En attente de la validation de la BCEAO pour passer à la généralisation.',
                    'Le dernier rapport montre que tous les cas de test sont OK ; seuls les tests de disponibilité restent NON OK.',
                    'La BCEAO doit effectuer ses propres tests sur l\'application mobile avant la validation en production.',
                ],
                'synthese' => "Le pilote est en cours et la recette provisoire est signée. La généralisation est suspendue à la validation de la BCEAO, qui doit d'abord mener ses propres tests. Les tests de disponibilité restent le seul point technique ouvert.",
                'jalons' => [
                    ['Fiche de recette provisoire signée', '2026-08-12', '2026-08-12', ProgressStatus::Termine, false, null],
                    ['Validation BCEAO', '2026-08-19', null, ProgressStatus::NonDemarre, true, null],
                    ['PV de recette définitif', '2026-08-20', null, ProgressStatus::NonDemarre, true, null],
                    ['GO de la BHCI pour la généralisation', '2026-08-21', null, ProgressStatus::NonDemarre, true, null],
                ],
            ],
            [
                'code' => 'BHLINK-B2W',
                'nom' => 'BHLINK — Bank to Wallet & Wallet to Bank',
                'description' => "B2W & W2B permet aux clients de la banque d'effectuer, via la plateforme BHLINK (web et mobile), des transferts sécurisés entre leurs comptes bancaires et leurs wallets mobiles.",
                'client' => 'bhci', 'type' => ProjectType::Integration,
                'categorie' => ProjectCategory::Monetique, 'ordre' => 4, 'pays' => 'Côte d\'Ivoire',
                'debut' => '2025-12-01', 'fin_initiale' => '2026-01-16', 'fin_revisee' => '2026-08-26',
                'avancement' => 91, 'taux' => 88,
                'lien' => 'https://groupemediasoftcom.sharepoint.com/:u:/s/ProjetsOrganisation/EUQ8eLuX1uBIkqnCAwRy5mUB7EcW-gQeSdkIyKmMR7_IdQ',
                'cp' => 'serge', 'backup' => null, 'sponsor' => 'stephanie', 'reftech' => 'bekouanKassi',
                'testeurs' => ['zie'],
                'etape' => 'Généralisation', 'annotation' => 'suivi post-généralisation',
                'phase' => 'EXECUTION', 'livres' => 'DEPLOIEMENT',
                'realisees' => [
                    ['Généralisation de la solution', '2026-08-11', ProgressStatus::Termine],
                    ['Suivi post-généralisation', '2026-08-14', ProgressStatus::EnCours],
                ],
                'a_realiser' => [
                    ['Poursuivre le suivi post-généralisation jusqu\'au 21/08/2026', '2026-08-21', ProgressStatus::EnCours],
                    ['Valider la date de clôture avec le client (26/08/2026)', '2026-08-21', ProgressStatus::NonDemarre],
                ],
                'attention' => [
                    'RAS — le projet se déroule conformément au planning révisé.',
                ],
                'synthese' => "Généralisation terminée fin juillet. Le suivi post-généralisation court jusqu'au 21 août ; la date de clôture du 26 août reste à valider avec la BHCI.",
                'jalons' => [
                    ['Généralisation', '2026-07-28', '2026-07-28', ProgressStatus::Termine, true, null],
                    ['Fin du suivi post-généralisation', '2026-08-21', null, ProgressStatus::EnCours, false, null],
                    ['Réunion de clôture', '2026-08-26', null, ProgressStatus::NonDemarre, true, null],
                ],
            ],
            [
                'code' => 'VERSUS-PI-SPI',
                'nom' => 'PI SPI BCEAO — Versus Bank',
                'description' => "Ce projet vise à permettre aux clients de Versus Bank d'effectuer, depuis Versus Net Pro (mobile), des transactions instantanées en s'appuyant sur l'infrastructure de la BCEAO, conformément aux exigences réglementaires en vigueur.",
                'client' => 'versus', 'type' => ProjectType::Integration,
                'categorie' => ProjectCategory::Monetique, 'ordre' => 5, 'pays' => 'Côte d\'Ivoire',
                'debut' => '2026-06-10', 'fin_initiale' => '2026-08-21', 'fin_revisee' => null,
                'avancement' => 33.33, 'taux' => 41,
                'cp' => 'serge', 'backup' => 'siaka', 'sponsor' => 'olivier', 'reftech' => 'karim',
                'testeurs' => ['zie'],
                'etape' => 'Adaptation', 'annotation' => 'homologation app mobile 41 %',
                'phase' => 'REALISATION', 'livres' => 'CONCEPTION',
                'realisees' => [
                    ['Prérequis administratifs', '2026-08-10', ProgressStatus::Termine],
                    ['Déploiement SANDBOX', '2026-08-11', ProgressStatus::Termine],
                    ['Configuration du schéma comptable', '2026-08-12', ProgressStatus::Termine],
                    ['Développement des API SIB', '2026-08-13', ProgressStatus::Termine],
                    ['Homologation Paiement instantané (100 %) et Gestion des alias (100 %)', '2026-08-13', ProgressStatus::Termine],
                    ['Homologation de l\'application mobile (41 %)', '2026-08-14', ProgressStatus::EnCours],
                    ['Validation de la maquette', '2026-08-14', ProgressStatus::EnCours],
                ],
                'a_realiser' => [
                    ['Parcours avec le client', '2026-08-18', ProgressStatus::NonDemarre],
                    ['Tests d\'acceptation utilisateur (UAT) du 19 au 28 août 2026', '2026-08-19', ProgressStatus::NonDemarre],
                ],
                'attention' => [
                    'Le retard dans l\'achat et l\'installation des serveurs de production risque de retarder la mise en production.',
                    'La validation de la maquette prend plus de temps que prévu : plusieurs captures ont été rejetées, l\'UX apporte les corrections.',
                ],
                'synthese' => "Homologations Paiement instantané et Gestion des alias à 100 %. L'homologation de l'application mobile plafonne à 41 %, freinée par la validation de la maquette. Le parcours client et les UAT s'enchaînent à partir du 18 août, mais l'approvisionnement des serveurs de production reste le risque majeur.",
                'jalons' => [
                    ['Homologation Paiement instantané', '2026-08-13', '2026-08-13', ProgressStatus::Termine, false, null],
                    ['Parcours avec le client', '2026-08-18', null, ProgressStatus::NonDemarre, true, null],
                    ['Fin des UAT', '2026-08-28', null, ProgressStatus::NonDemarre, true, null],
                    ['Livraison des serveurs de production', '2026-08-14', null, ProgressStatus::Bloque, true, null],
                ],
            ],
            [
                'code' => 'CORIS-VEROPAY',
                'nom' => 'VEROPAY pour Coris Bank',
                'description' => "Plateforme digitale de paiement multi-services et d'acceptation multi-opérateurs.",
                'client' => 'coris', 'type' => ProjectType::Integration,
                'categorie' => ProjectCategory::Monetique, 'ordre' => 6, 'pays' => 'Côte d\'Ivoire',
                'debut' => '2025-09-26', 'fin_initiale' => '2025-11-11', 'fin_revisee' => '2026-09-25',
                'avancement' => 75, 'taux' => 82,
                'lien' => 'https://groupemediasoftcom.sharepoint.com/:u:/s/ProjetsOrganisation/ERP-VWo8FUdMpolCxHX900EBPZFXEYLNj1dgtCkIVVoEJQ',
                'cp' => 'kevin', 'backup' => 'michee', 'sponsor' => 'oumar', 'reftech' => 'alex',
                'testeurs' => ['zie'],
                'etape' => 'Pilote', 'annotation' => '0 %',
                'phase' => 'QUALIFICATION', 'livres' => 'CONCEPTION',
                'realisees' => [
                    ['Ouverture du compte de commissionnement de Mediasoft à Coris', '2026-08-12', ProgressStatus::Termine],
                    ['Correction par MS Solution pour permettre les paiements Wave sur TPE Linux', '2026-08-14', ProgressStatus::EnCours],
                    ['Réunion technique sur la connectivité entre les serveurs', '2026-08-14', ProgressStatus::Bloque],
                ],
                'a_realiser' => [
                    ['Préparer et transmettre la réponse au courrier de Coris Bank (TPE Linux et paiement Wave)', '2026-08-18', ProgressStatus::NonDemarre],
                    ['Faire un point sur l\'état d\'avancement de l\'intégration de Wave sur les TPE Linux', '2026-08-19', ProgressStatus::NonDemarre],
                    ['Reprogrammer une session sur la connectivité entre les serveurs avec les équipes Coris Bank', '2026-08-20', ProgressStatus::NonDemarre],
                ],
                'attention' => [
                    'Courrier Coris Bank reçu le 07/07/2026, accusé de réception transmis le 08/07/2026 : malgré le délai de 7 jours prévu, aucune réponse n\'a été apportée à ce jour.',
                    'La réunion technique sur la connectivité entre serveurs du 14/08/2026 a été annulée en raison de l\'indisponibilité de l\'équipe Coris Bank.',
                ],
                'synthese' => 'Le projet est bloqué sur deux fronts côté client : le courrier du 07 juillet reste sans réponse et la session technique sur la connectivité a été annulée. Le pilote ne peut démarrer tant que les paiements Wave sur TPE Linux ne sont pas corrigés.',
                'jalons' => [
                    ['Réponse au courrier Coris Bank du 07/07/2026', '2026-07-15', null, ProgressStatus::EnCours, true, null],
                    ['Connectivité inter-serveurs validée', '2026-08-14', null, ProgressStatus::Bloque, true, null],
                    ['Démarrage du pilote', '2026-09-01', null, ProgressStatus::NonDemarre, true, null],
                ],
            ],

            // ───────────────────────── PROJETS BANQUES DIGITALES ─────────────────────────
            [
                'code' => 'BBCI-EZIKASH',
                'nom' => 'Déploiement plateforme digitale bancaire à la BBCI',
                'description' => "Projet de transformation digitale à travers une plateforme bancaire numérique offrant la banque à distance, un porte-monnaie électronique, l'agency banking, le paiement, les transferts d'argent et une gestion administrative.",
                'client' => 'bbci', 'type' => ProjectType::Deploiement,
                'categorie' => ProjectCategory::BanqueDigitale, 'ordre' => 1, 'pays' => 'Burundi',
                'debut' => '2025-08-04', 'fin_initiale' => '2026-03-31', 'fin_revisee' => '2026-09-01',
                'avancement' => 92, 'taux' => 96,
                'cp' => 'anselme', 'backup' => 'siaka', 'sponsor' => 'stephanie', 'reftech' => 'alex',
                'testeurs' => ['gbadje'],
                'etape' => 'Pilote', 'annotation' => 'ouvert au staff BBCI et à un panel de clients externes',
                'phase' => 'EXECUTION', 'livres' => 'DEPLOIEMENT',
                'realisees' => [
                    ['Restitution du rapport SBS et retest de confirmation', '2026-08-10', ProgressStatus::Termine],
                    ['Pilote Agency Banking & Paiement Marchand (2 semaines)', '2026-08-11', ProgressStatus::Termine],
                    ['Préparer le COPIL du 14/08/2026', '2026-08-13', ProgressStatus::Termine],
                    ['Prise en compte des remédiations du 1er rapport SBS (interface web)', '2026-08-14', ProgressStatus::EnCours],
                ],
                'a_realiser' => [
                    ['Obtenir la confirmation écrite SBS du retest de conformité', '2026-08-18', ProgressStatus::EnCours],
                    ['Publication App Store — levée de la validation Apple', '2026-08-20', ProgressStatus::Bloque],
                    ['Généralisation des modules et ouverture au public', '2026-08-21', ProgressStatus::NonDemarre],
                ],
                'attention' => [
                    'Le périmètre initial SBS n\'incluait pas de retest après remédiation : un retest de confirmation a été intégré à la demande du comité de gouvernance et doit être confirmé par écrit par SBS.',
                    'Gel applicatif total du 20/07/2026 au 31/07/2026 pour finaliser la campagne de tests SBS.',
                    'La publication sur l\'App Store reste en attente de validation Apple.',
                ],
                'synthese' => 'Projet en production depuis le 27/06/2026, avancement global 92 %. La clôture des tests SBS conditionne la généralisation de mi-août et le lancement officiel e-Zikash, à arbitrer au comité de gouvernance du 21/08/2026.',
                'jalons' => [
                    ['Mise en production (go-live, tous modules)', '2026-06-27', '2026-06-27', ProgressStatus::Termine, true, null],
                    ['Publication des applications (Play Store & App Store)', '2026-07-19', '2026-07-19', ProgressStatus::Termine, false, null],
                    ['Comité de gouvernance', '2026-07-24', '2026-07-24', ProgressStatus::Termine, false, 'comite_gouvernance'],
                    ['Rapport final SBS', '2026-08-07', '2026-08-07', ProgressStatus::Termine, true, null],
                    ['COPIL', '2026-08-14', '2026-08-14', ProgressStatus::Termine, false, 'copil'],
                    ['Comité de gouvernance — lancement officiel e-Zikash', '2026-08-21', null, ProgressStatus::NonDemarre, true, 'comite_gouvernance'],
                ],
            ],
            [
                'code' => 'OBA-MOBILE',
                'nom' => 'OBA Mobile',
                'description' => "Service permettant à la clientèle corporate d'Orange Bank d'avoir une application mobile leur donnant une vue 360° sur les différentes opérations qu'ils effectuent.",
                'client' => 'oba', 'type' => ProjectType::Deploiement,
                'categorie' => ProjectCategory::BanqueDigitale, 'ordre' => 2, 'pays' => 'Côte d\'Ivoire',
                'debut' => '2025-01-23', 'fin_initiale' => '2025-03-20', 'fin_revisee' => '2026-09-16',
                'avancement' => 66.7, 'taux' => 60.32,
                'lien' => 'https://groupemediasoftcom.sharepoint.com/:u:/s/ProjetsOrganisation/EfC1wONc2plOursIIXYfKR4BGQOh-Imoh7Vb9XKM30-lDw',
                'cp' => 'kevin', 'backup' => 'ben', 'sponsor' => 'stephanie', 'reftech' => 'bekouanKassi',
                'testeurs' => ['zie'],
                'etape' => 'Test d\'acceptation utilisateur — UAT', 'annotation' => '90 %',
                'phase' => 'QUALIFICATION', 'livres' => 'REALISATION',
                'realisees' => [
                    ['Transmettre le compte rendu de l\'atelier technique sur l\'utilisation du clavier natif', '2026-08-10', ProgressStatus::Termine],
                    ['Réceptionner le lien avec activation du clavier natif sur la version OBA Mobile Android', '2026-08-11', ProgressStatus::Termine],
                    ['Réaliser les tests sécuritaires et fonctionnels', '2026-08-11', ProgressStatus::EnCours],
                    ['Transmettre l\'analyse de risques liée à l\'utilisation du clavier natif à Orange Bank', '2026-08-12', ProgressStatus::Termine],
                ],
                'a_realiser' => [
                    ['Finaliser les tests sécuritaires et fonctionnels sur la version OBA Mobile Android', '2026-08-17', ProgressStatus::EnCours],
                    ['Mettre à jour les CGU', '2026-08-17', ProgressStatus::NonDemarre],
                    ['Transmettre les différents liens à Orange Bank pour validation', '2026-08-18', ProgressStatus::NonDemarre],
                    ['Accompagner les équipes Orange Bank dans la réalisation de leurs tests sur le clavier natif', '2026-08-19', ProgressStatus::NonDemarre],
                    ['Obtenir le PV de recette provisoire UAT', '2026-08-21', ProgressStatus::NonDemarre],
                ],
                'attention' => [
                    'UAT en attente de la validation du clavier natif par Orange Bank.',
                    'Maintenir un suivi rapproché avec les équipes Orange Bank afin d\'éviter tout retard pour le passage en pilote.',
                ],
                'synthese' => "L'UAT est à 90 % mais reste suspendue à la validation du clavier natif par Orange Bank. L'analyse de risques a été transmise le 12 août ; l'obtention du PV de recette provisoire est visée pour le 21 août.",
                'jalons' => [
                    ['Validation du clavier natif par Orange Bank', '2026-08-19', null, ProgressStatus::EnCours, true, null],
                    ['PV de recette provisoire UAT', '2026-08-21', null, ProgressStatus::NonDemarre, true, null],
                    ['Passage en pilote', '2026-09-01', null, ProgressStatus::NonDemarre, true, null],
                ],
            ],
            [
                'code' => 'VERSUS-NETPRO',
                'nom' => 'Versus Net Pro Mobile',
                'description' => "Mettre à disposition de la clientèle corporate de Versus Bank une solution digitale pour le traitement de leurs requêtes au quotidien, afin de réduire leur temps d'attente et de renforcer l'image de la banque.",
                'client' => 'versus', 'type' => ProjectType::Deploiement,
                'categorie' => ProjectCategory::BanqueDigitale, 'ordre' => 3, 'pays' => 'Côte d\'Ivoire',
                'debut' => '2025-02-12', 'fin_initiale' => '2025-03-18', 'fin_revisee' => '2026-08-25',
                'avancement' => 91.66, 'taux' => 90,
                'lien' => 'https://groupemediasoftcom.sharepoint.com/:u:/s/ProjetsOrganisation/EUQliXV02e1MudU7xvDiKp0Bk9UOCpup1fRuw6Qc6NIpVw',
                'cp' => 'serge', 'backup' => 'anselme', 'sponsor' => 'olivier', 'reftech' => 'bekouanMartial',
                'testeurs' => ['zie'],
                'etape' => 'Généralisation', 'annotation' => 'suivi post-généralisation',
                'phase' => 'EXECUTION', 'livres' => 'DEPLOIEMENT',
                'realisees' => [
                    ['Suivi post-généralisation', '2026-08-14', ProgressStatus::EnCours],
                ],
                'a_realiser' => [
                    ['Proposer une date de clôture à valider avec le client (mardi 25/08/2026)', '2026-08-21', ProgressStatus::NonDemarre],
                    ['Clôturer le projet du 24 au 28 août 2026', '2026-08-28', ProgressStatus::NonDemarre],
                ],
                'attention' => [
                    'Le taux de réalisation n\'était pas renseigné dans le dossier du comité : il est à confirmer par le chef de projet.',
                ],
                'synthese' => "Projet en phase de suivi post-généralisation, avancement 91,66 %. Il ne reste qu'à convenir d'une date de clôture avec Versus Bank, proposée au 25 août 2026.",
                'jalons' => [
                    ['Généralisation', '2026-07-31', '2026-07-31', ProgressStatus::Termine, true, null],
                    ['Réunion de clôture', '2026-08-25', null, ProgressStatus::NonDemarre, true, null],
                ],
            ],
            [
                'code' => 'BDM-KUNKAN',
                'nom' => 'BDM Kunkan — montée de version VITBANK 25',
                'description' => "Projet de montée de version de l'application de banque en ligne BDM Kunkan actuelle vers la version VITBANK 25.",
                'client' => 'bdm', 'type' => ProjectType::Integration,
                'categorie' => ProjectCategory::BanqueDigitale, 'ordre' => 4, 'pays' => 'Mali',
                'debut' => '2026-07-14', 'fin_initiale' => '2026-09-29', 'fin_revisee' => null,
                'avancement' => 30, 'taux' => 35,
                'lien' => 'https://groupemediasoftcom.sharepoint.com/:u:/s/ProjetsOrganisation/EYPCQhjLxqlMshAME0Fr-_EB-YFtZLIUWQpm-IW0g1SKmw',
                'cp' => 'ben', 'backup' => null, 'sponsor' => 'flatene', 'reftech' => 'alex',
                'testeurs' => ['lacina'],
                'etape' => 'Tests fonctionnels', 'annotation' => 'interface web client en cours',
                'phase' => 'QUALIFICATION', 'livres' => 'CONCEPTION',
                'realisees' => [
                    ['Atelier Marketing', '2026-08-10', ProgressStatus::Termine],
                    ['Test fonctionnel de l\'interface Web Admin (03 au 10/08/2026)', '2026-08-10', ProgressStatus::Termine],
                    ['Test fonctionnel de l\'interface Web client (13 au 17/08/2026)', '2026-08-13', ProgressStatus::EnCours],
                    ['Tests de sécurité (03 au 20/08/2026)', '2026-08-14', ProgressStatus::EnCours],
                    ['Mise en place des API : création client et création compte', '2026-08-14', ProgressStatus::EnCours],
                    ['Cadrage', '2026-08-14', ProgressStatus::EnCours],
                ],
                'a_realiser' => [
                    ['Terminer la mise en place des API (création client, création compte)', '2026-08-21', ProgressStatus::EnCours],
                    ['Terminer le cadrage', '2026-08-21', ProgressStatus::EnCours],
                    ['Organiser l\'atelier Comptable (date à définir)', '2026-08-21', ProgressStatus::NonDemarre],
                    ['Test fonctionnel de l\'interface Mobile Client Android (18 au 19/08/2026)', '2026-08-19', ProgressStatus::NonDemarre],
                    ['Test fonctionnel de l\'interface Mobile Client iOS (20 au 21/08/2026)', '2026-08-21', ProgressStatus::NonDemarre],
                ],
                'attention' => [
                    'Les tests fonctionnels de l\'interface web client n\'ont pu se tenir dans les délais pour deux raisons : dépriorisation au profit des tests WhatsApp, et indisponibilité de la plateforme BDM Kunkan sur la demi-journée du 13/08/2026.',
                    'Les tests de l\'interface web client prendront fin le 17/08/2026.',
                ],
                'synthese' => "Projet lancé le 14 juillet, avancement 30 %. Les tests de l'interface web admin sont validés ; ceux de l'interface web client, décalés par la priorité donnée aux tests WhatsApp, se terminent le 17 août. Les tests mobiles Android et iOS suivent la semaine du 17 août.",
                'jalons' => [
                    ['Fin des tests fonctionnels web admin', '2026-08-10', '2026-08-10', ProgressStatus::Termine, false, null],
                    ['Fin des tests fonctionnels web client', '2026-08-17', null, ProgressStatus::EnCours, true, null],
                    ['Fin des tests de sécurité', '2026-08-20', null, ProgressStatus::EnCours, true, null],
                    ['Fin de projet', '2026-09-29', null, ProgressStatus::NonDemarre, true, null],
                ],
            ],
            [
                'code' => 'BMS-TURAPAY',
                'nom' => 'Application mobile TURAPAY',
                'description' => "TURAPAY est une application mobile financière qui permet aux utilisateurs d'ouvrir un compte bancaire sans frais de gestion, d'obtenir une carte Visa pour des paiements en ligne et hors ligne, et d'accéder à des services de transferts et de virements.",
                'client' => 'bms', 'type' => ProjectType::Developpement,
                'categorie' => ProjectCategory::BanqueDigitale, 'ordre' => 5, 'pays' => 'Mali',
                'debut' => '2024-07-03', 'fin_initiale' => null, 'fin_revisee' => null,
                'avancement' => 30, 'taux' => 42,
                'cp' => 'ben', 'backup' => null, 'sponsor' => 'flatene', 'reftech' => 'arnaud',
                'testeurs' => ['zie'],
                'etape' => 'Développement', 'annotation' => 'API et interfaces',
                'phase' => 'REALISATION', 'livres' => 'CADRAGE',
                'realisees' => [
                    ['Mise en place des API et interfaces : API Vitbank Paylogic (callback et vérification de transaction)', '2026-08-11', ProgressStatus::Termine],
                    ['API Cartes : intégration de la fonction de retrait sans carte', '2026-08-12', ProgressStatus::Termine],
                    ['API Gestion BackOffice : clients onboardés, cartes virtuelles, cartes physiques, transactions', '2026-08-13', ProgressStatus::Termine],
                    ['Interfaces mobile et BackOffice : retrait sans carte au DAB, consultation des cartes et des comptes bancaires', '2026-08-14', ProgressStatus::Termine],
                ],
                'a_realiser' => [
                    ['Terminer le cadrage', '2026-08-21', ProgressStatus::EnCours],
                    ['API Gestion CBS : intégration de l\'API callback de notification de retrait sans carte', '2026-08-18', ProgressStatus::NonDemarre],
                    ['API Gestion BackOffice : endpoints de consultation et d\'enregistrement de la piste d\'audit', '2026-08-19', ProgressStatus::NonDemarre],
                    ['Interface BackOffice : consultation de la piste d\'audit, gestion du plafonnement et du déplafonnement', '2026-08-21', ProgressStatus::NonDemarre],
                ],
                'attention' => [
                    'Le planning du projet reste à valider : aucune date de fin n\'est arrêtée à ce jour.',
                    'Projet démarré le 03/07/2024 sans échéance contractuelle formalisée — cadrage à finaliser en priorité.',
                ],
                'synthese' => "Le lot d'API et d'interfaces de la semaine est livré. Le projet reste toutefois sans date de fin arrêtée depuis son lancement en juillet 2024 : la validation du planning et la clôture du cadrage sont les deux prérequis à sécuriser.",
                'jalons' => [
                    ['Validation du planning projet', '2026-08-21', null, ProgressStatus::NonDemarre, true, null],
                    ['Clôture du cadrage', '2026-08-21', null, ProgressStatus::EnCours, true, null],
                ],
            ],
            [
                'code' => 'BMS-WHATSAPP',
                'nom' => 'WhatsApp Banking V1/V2 — BMS Kibaru',
                'description' => "Permettre aux clients de la banque d'accéder à certains services bancaires directement depuis WhatsApp, de manière simple, rapide et sécurisée. La version V1 offre quatre fonctionnalités : association d'un compte bancaire, consultation de solde, affichage des dernières transactions et modification du code PIN.",
                'client' => 'bms', 'type' => ProjectType::Developpement,
                'categorie' => ProjectCategory::BanqueDigitale, 'ordre' => 6, 'pays' => 'Mali',
                'debut' => '2026-04-01', 'fin_initiale' => '2026-06-30', 'fin_revisee' => '2026-08-19',
                'avancement' => 65, 'taux' => 83,
                'cp' => 'siaka', 'backup' => 'sandrine', 'sponsor' => 'flatene', 'reftech' => 'jeanpaul',
                'testeurs' => ['lacina'],
                'etape' => 'Test d\'acceptation utilisateur — UAT', 'annotation' => 'V1 — pilote en préparation',
                'phase' => 'QUALIFICATION', 'livres' => 'REALISATION',
                'realisees' => [
                    ['Préparation de l\'environnement de production pour le pilote de la V1', '2026-08-11', ProgressStatus::Termine],
                    ['Ouverture des UAT sur AIF test et tests de sécurité de la V1', '2026-08-13', ProgressStatus::EnCours],
                    ['Fonctionnalités transactionnelles AIP Rest WhatsApp (opérations prédéfinies, récupération des frais, virement interne)', '2026-08-14', ProgressStatus::EnCours],
                    ['Adaptation du module de facturation', '2026-08-14', ProgressStatus::EnCours],
                ],
                'a_realiser' => [
                    ['V2 — Intégration API Business : endpoints relevé, RIB et extrait', '2026-08-19', ProgressStatus::NonDemarre],
                    ['V2 — Intégration API Business : endpoint de virement interne', '2026-08-21', ProgressStatus::NonDemarre],
                ],
                'attention' => [
                    'L\'adaptation du module de facturation n\'est plus un point bloquant : le module pourra être mis à la disposition de la clientèle à la suite du pilote.',
                    'Les tests WhatsApp ont été priorisés au détriment des tests BDM Kunkan sur la semaine.',
                ],
                'synthese' => "La V1 entre en pilote le 19 août : environnement de production prêt, UAT et tests de sécurité en cours. Les travaux V2 sur l'API Business démarrent en parallèle.",
                'jalons' => [
                    ['Environnement de production prêt pour le pilote V1', '2026-08-11', '2026-08-11', ProgressStatus::Termine, false, null],
                    ['Pilote de la V1', '2026-08-19', null, ProgressStatus::NonDemarre, true, null],
                ],
            ],

            // ──────────────────────── AUTRES TYPES DE PROJETS ────────────────────────
            [
                'code' => 'BAM-MOBILE',
                'nom' => 'Application mobile BAM',
                'description' => "L'application mobile Bridge Asset Management est une solution de gestion de patrimoine, conçue pour offrir aux clients de Bridge Asset Management un accès aux marchés financiers et leur permettre d'investir sur des fonds de placement selon leur profil de risque.",
                'client' => 'bam', 'type' => ProjectType::Developpement,
                'categorie' => ProjectCategory::Autres, 'ordre' => 1, 'pays' => 'Côte d\'Ivoire',
                'debut' => '2024-11-11', 'fin_initiale' => '2024-12-15', 'fin_revisee' => '2026-08-17',
                'avancement' => 93, 'taux' => 64.28,
                'lien' => 'https://groupemediasoftcom.sharepoint.com/:u:/s/ProjetsOrganisation/ERP-VWo8FUdMpolCxHX900EBPZFXEYLNj1dgtCkIVVoEJQ',
                'cp' => 'ben', 'backup' => null, 'sponsor' => 'fofana', 'reftech' => 'yacouba',
                'testeurs' => ['gbadje'],
                'etape' => 'Test d\'acceptation utilisateur — UAT', 'annotation' => 'en cours',
                'phase' => 'QUALIFICATION', 'livres' => 'REALISATION',
                'realisees' => [
                    ['Déploiement en environnement de test BAM', '2026-08-10', ProgressStatus::Termine],
                    ['Convocation du COPIL avec le client', '2026-08-12', ProgressStatus::Termine],
                    ['Prise en compte des remontées du client lors des UAT', '2026-08-14', ProgressStatus::Termine],
                    ['Rédaction des manuels utilisateurs', '2026-08-14', ProgressStatus::Termine],
                    ['Tests d\'acceptation utilisateur (UAT)', '2026-08-14', ProgressStatus::EnCours],
                ],
                'a_realiser' => [
                    ['Organiser la réunion de validation finale en présentiel', '2026-08-18', ProgressStatus::NonDemarre],
                    ['Obtenir la signature du PV de recette provisoire', '2026-08-19', ProgressStatus::NonDemarre],
                    ['Déployer en environnement de préproduction', '2026-08-20', ProgressStatus::NonDemarre],
                    ['Planifier une séance de présentation de l\'application au régulateur (AMF)', '2026-08-21', ProgressStatus::NonDemarre],
                ],
                'attention' => [
                    'Date de fin à réviser dans l\'attente de la signature du PV de recette et de la date de présentation au régulateur.',
                    'Mises à jour fréquentes des API du côté de KCS.',
                    'Blocage sur les autorisations du port de communication 4215 dans l\'environnement BAM.',
                    'Le COPIL est convoqué mais sa tenue reste suspendue à la disponibilité du client.',
                ],
                'synthese' => "Avancement déclaré à 93 % pour un taux de réalisation de 64,28 % : l'écart s'explique par les remontées UAT encore en cours d'intégration. La signature du PV de recette provisoire et la présentation à l'AMF conditionnent la révision de la date de fin.",
                'jalons' => [
                    ['Déploiement en environnement de test BAM', '2026-07-27', '2026-07-27', ProgressStatus::Termine, false, null],
                    ['Réunion de validation finale en présentiel', '2026-08-18', null, ProgressStatus::NonDemarre, true, null],
                    ['Signature du PV de recette provisoire', '2026-08-19', null, ProgressStatus::NonDemarre, true, null],
                    ['Présentation au régulateur (AMF)', '2026-08-21', null, ProgressStatus::NonDemarre, true, null],
                ],
            ],
            [
                'code' => 'BNI-SGIFS',
                'nom' => 'Montée de version SGIFS',
                'description' => "Projet de montée de version de l'application de gestion des fonds sectoriels, plateforme permettant à la BNI de suivre, contrôler et optimiser l'utilisation des ressources financières dédiées au financement de projets, avec intégration du plan comptable à la norme SYCEBNL.",
                'client' => 'bni', 'type' => ProjectType::Integration,
                'categorie' => ProjectCategory::Autres, 'ordre' => 2, 'pays' => 'Côte d\'Ivoire',
                'debut' => '2025-05-05', 'fin_initiale' => '2025-08-30', 'fin_revisee' => null,
                'avancement' => 70, 'taux' => 64.3,
                'lien' => 'https://groupemediasoftcom.sharepoint.com/:u:/s/ProjetsOrganisation/EWwLdpE9-N1Eg7Q8VraXHRUB0bl2kMTxYB2mpzHPW8xfjQ',
                'cp' => 'siaka', 'backup' => 'michee', 'sponsor' => 'sanogo', 'reftech' => 'franck',
                'testeurs' => ['jeanne'],
                'etape' => 'Adaptation', 'annotation' => 'implémentation PCB SYCEBNL',
                'phase' => 'REALISATION', 'livres' => 'CONCEPTION',
                'realisees' => [
                    ['Analyse et évaluation du plan de charge', '2026-08-11', ProgressStatus::Termine],
                    ['Feuille de route actualisée : recadrage transmis à la BNI intégrant le plan de charge ML et les prérequis banque', '2026-08-12', ProgressStatus::Termine],
                    ['Mise à disposition des prérequis indispensables par la BNI (tableau de correspondance, validations fonctionnelles, environnements techniques)', '2026-08-14', ProgressStatus::EnCours],
                ],
                'a_realiser' => [
                    ['Relancer le sponsor et le client sur la mise à disposition des prérequis', '2026-08-18', ProgressStatus::NonDemarre],
                    ['Démarrer les travaux d\'implémentation du PCB SYCEBNL', '2026-08-21', ProgressStatus::NonDemarre],
                ],
                'attention' => [
                    'La BNI a confirmé son exigence : intégration complète et préalable de la norme SYCEBNL avant toute mise en production.',
                    'Le projet est en attente des prérequis côté banque ; une relance du sponsor et du client a été engagée.',
                    'La date de fin révisée reste à définir.',
                ],
                'synthese' => "Le recadrage a été transmis à la BNI le 04 août. Le démarrage de l'implémentation du PCB SYCEBNL est bloqué par la mise à disposition des prérequis côté banque, qui fait l'objet d'une relance du sponsor.",
                'jalons' => [
                    ['Transmission du recadrage à la BNI', '2026-08-04', '2026-08-04', ProgressStatus::Termine, false, null],
                    ['Mise à disposition des prérequis par la BNI', '2026-08-14', null, ProgressStatus::Bloque, true, null],
                    ['Démarrage de l\'implémentation PCB SYCEBNL', '2026-08-21', null, ProgressStatus::NonDemarre, true, null],
                ],
            ],
            [
                'code' => 'BNI-ALTERNATIS',
                'nom' => 'ALTERNATIS',
                'description' => "ALTERNATIS est une plateforme de communication multicanale conçue pour assurer l'envoi fiable et sécurisé de notifications aux clients des applications du Groupe Mediasoft Lafayette. La solution centralise l'envoi de SMS, e-mails et messages WhatsApp en s'appuyant sur plusieurs fournisseurs et un mécanisme de basculement automatique garantissant la continuité du service.",
                'client' => 'bni', 'type' => ProjectType::Developpement,
                'categorie' => ProjectCategory::Autres, 'ordre' => 3, 'pays' => 'Côte d\'Ivoire',
                'debut' => '2026-06-22', 'fin_initiale' => '2026-09-25', 'fin_revisee' => null,
                'avancement' => 33.33, 'taux' => 65,
                'cp' => 'serge', 'backup' => null, 'sponsor' => 'olivier', 'reftech' => 'lamine',
                'testeurs' => [],
                'etape' => 'Développement', 'annotation' => 'connexion fournisseurs',
                'phase' => 'REALISATION', 'livres' => 'CONCEPTION',
                'realisees' => [
                    ['Module Authentification', '2026-08-10', ProgressStatus::Termine],
                    ['Connexion modem SMS', '2026-08-11', ProgressStatus::Termine],
                    ['Connexion client (banque)', '2026-08-12', ProgressStatus::Termine],
                    ['Gestion des files d\'attente', '2026-08-13', ProgressStatus::Termine],
                    ['Gestion OTP', '2026-08-13', ProgressStatus::Termine],
                    ['Connexion fournisseur', '2026-08-14', ProgressStatus::EnCours],
                ],
                'a_realiser' => [
                    ['Terminer la connexion fournisseur', '2026-08-18', ProgressStatus::EnCours],
                    ['Test de connexion des fournisseurs', '2026-08-20', ProgressStatus::NonDemarre],
                    ['Test d\'envoi des messages', '2026-08-21', ProgressStatus::NonDemarre],
                ],
                'attention' => [
                    'Présentation de la plateforme faite à M. Guy René KAMARA du 03 au 06 août 2026.',
                    'Le taux de réalisation (65 %) dépasse nettement l\'avancement déclaré (33,33 %) : la cotation d\'avancement est à réexaminer avec le chef de projet.',
                ],
                'synthese' => "Cinq modules sur six sont livrés ; seule la connexion fournisseur reste à finaliser avant les tests d'envoi. Écart notable entre avancement déclaré et taux de réalisation, à arbitrer.",
                'jalons' => [
                    ['Présentation à M. Guy René KAMARA', '2026-08-06', '2026-08-06', ProgressStatus::Termine, false, null],
                    ['Fin des tests d\'envoi de messages', '2026-08-21', null, ProgressStatus::NonDemarre, false, null],
                    ['Fin de projet', '2026-09-25', null, ProgressStatus::NonDemarre, true, null],
                ],
            ],
        ];
    }

    /**
     * Évolution du portefeuille sur la semaine du 17 au 21 août 2026, relevée dans
     * les dossiers de KEVIN ZAGO, BEN CISSÉ, SERGE GOUETY et SIAKA DONAFANNY.
     *
     * BBCI e-Zikash n'apparaît dans aucun des quatre dossiers de cette semaine :
     * son état reste donc celui du comité précédent.
     *
     * @return array<string, array<string, mixed>>
     */
    private function misesAJour(): array
    {
        return [
            'BRB-IPS-LOT1' => [
                'avancement' => 97, 'taux' => 96,
                'realisees' => [
                    ['Reprise des 17 blocs de tests avec la BRB — 14 blocs validés sur 17', '2026-08-21', ProgressStatus::EnCours],
                    ["Travaux d'homologation AIP BRB — planning révisé au 23 août 2026", '2026-08-21', ProgressStatus::EnCours],
                ],
                'a_realiser' => [
                    ["Valider en présentiel avec l'application mobile", '2026-08-25', ProgressStatus::NonDemarre],
                    ['Terminer les 3 derniers blocs de tests avec la BRB', '2026-08-28', ProgressStatus::NonDemarre],
                ],
                'attention' => [
                    'Risques de connectivité et soutien BRB : instabilité des serveurs et coupure de fibre optique identifiées ; la connectivité est surveillée et une demande d\'assistance à la BRB est anticipée.',
                    'La validation en présentiel est prévue le 25 août, soit après la date de fin révisée du 23 août : l\'échéance doit être réajustée.',
                ],
                'synthese' => 'Avancement porté à 97 %. Quatorze des dix-sept blocs de tests sont validés avec la BRB. La validation en présentiel du 25 août intervient après la date de fin révisée du 23 août, qui demande donc à être repoussée formellement.',
            ],

            'BRB-IPS-LOT2' => [
                'avancement' => 30, 'taux' => 33,
                'realisees' => [
                    ['Module 3 — Application mobile : exposition des API pour les canaux', '2026-08-19', ProgressStatus::Termine],
                    ["Parcours interne de stabilisation de l'application (technique et panel BBCI)", '2026-08-21', ProgressStatus::EnCours],
                    ['Module 5 — Comptabilité / CBS : configurations comptables et échanges CBS', '2026-08-21', ProgressStatus::EnCours],
                ],
                'a_realiser' => [
                    ["Module 4 — Homologation BRB en présentiel sur l'application mobile", '2026-08-25', ProgressStatus::NonDemarre],
                ],
                'attention' => [
                    "La BRB fixe la date limite d'intégration des participants à Burundi Pay au plus tard le 05 septembre 2026.",
                    "L'avancement reste affiché à 30 % alors que trois modules sur neuf sont livrés : la cotation est à revoir avec le chef de projet.",
                ],
                'synthese' => "Le module 3 est livré et le module 5 (comptabilité / CBS) a démarré le 18 août. L'homologation BRB en présentiel du 25 août conditionne le respect de l'échéance réglementaire du 05 septembre.",
            ],

            'BHLINK-PI' => [
                'avancement' => 83.33, 'taux' => 95,
                'realisees' => [
                    ['Pilote', '2026-08-21', ProgressStatus::EnCours],
                ],
                'a_realiser' => [
                    ['Obtenir la validation de la BCEAO', '2026-08-26', ProgressStatus::NonDemarre],
                    ['Signature du PV de recette définitif', '2026-08-27', ProgressStatus::NonDemarre],
                    ['Obtenir le GO de la BHCI puis généraliser', '2026-08-28', ProgressStatus::NonDemarre],
                ],
                'attention' => [
                    'En attente de la validation de la BCEAO pour passer à la généralisation.',
                    'Tous les cas de test sont OK ; seuls les tests de disponibilité restent NON OK.',
                    "La BCEAO doit effectuer ses propres tests sur l'application mobile avant la validation en production.",
                ],
                'synthese' => "Situation inchangée depuis une semaine : le pilote se poursuit et la généralisation reste suspendue à la validation de la BCEAO, qui n'a pas encore lancé ses propres tests.",
            ],

            'BHLINK-B2W' => [
                'avancement' => 91, 'taux' => 88, 'fin_revisee' => '2026-09-01',
                'realisees' => [
                    ['Suivi post-généralisation', '2026-08-21', ProgressStatus::Termine],
                ],
                'a_realiser' => [
                    ['Valider la date de clôture avec la BHCI (01/09/2026)', '2026-08-28', ProgressStatus::NonDemarre],
                ],
                'attention' => [
                    'RAS — le suivi post-généralisation est arrivé à son terme le 21 août.',
                    'La date de clôture a glissé du 26 août au 01 septembre 2026.',
                ],
                'synthese' => "Le suivi post-généralisation s'achève comme prévu le 21 août. Il ne reste qu'à arrêter la date de clôture avec la BHCI, désormais visée au 01 septembre.",
            ],

            'VERSUS-PI-SPI' => [
                'avancement' => 66.66, 'taux' => 40,
                'etape' => 'Test d\'acceptation utilisateur — UAT', 'annotation' => 'homologation mobile 79,73 %',
                'realisees' => [
                    ['Parcours avec le client', '2026-08-18', ProgressStatus::Termine],
                    ["Homologation de l'application mobile — 79,73 %", '2026-08-21', ProgressStatus::EnCours],
                    ['Validation de la maquette — 649 preuves validées, 149 rejetées', '2026-08-21', ProgressStatus::EnCours],
                ],
                'a_realiser' => [
                    ['Terminer les tests d\'acceptation utilisateur (19 au 28 août 2026)', '2026-08-28', ProgressStatus::EnCours],
                ],
                'attention' => [
                    "Le retard dans l'achat et l'installation des serveurs de production risque de retarder la mise en production.",
                    'Validation de la maquette : 649 preuves validées et 149 rejetées ; l\'UX apporte les corrections.',
                    'La date de fin initiale du 21 août 2026 est atteinte sans date révisée arrêtée.',
                ],
                'synthese' => "Semaine de forte progression : l'avancement double, de 33 % à 66,66 %, porté par l'homologation de l'application mobile passée de 41 % à 79,73 %. Les UAT se terminent le 28 août. L'approvisionnement des serveurs de production reste le risque principal.",
            ],

            'CORIS-VEROPAY' => [
                'avancement' => 75, 'taux' => 82,
                'realisees' => [
                    ['Session de troubleshooting sur la connectivité entre les serveurs', '2026-08-18', ProgressStatus::Termine],
                    ['Réception de la réponse à la correspondance adressée à Mediasoft', '2026-08-20', ProgressStatus::Termine],
                    ['Correction MS Solution pour les paiements Wave sur TPE Linux', '2026-08-21', ProgressStatus::EnCours],
                ],
                'a_realiser' => [
                    ['Reprogrammer une séance sur la connectivité entre les serveurs', '2026-08-25', ProgressStatus::NonDemarre],
                    ['Relancer Coris Bank sur le retour du support MTN', '2026-08-26', ProgressStatus::NonDemarre],
                    ['Analyser les résultats des vérifications effectuées par MTN', '2026-08-27', ProgressStatus::NonDemarre],
                    ['Procéder aux tests de connectivité entre les deux infrastructures', '2026-08-28', ProgressStatus::NonDemarre],
                ],
                'attention' => [
                    "Action Coris Bank : ouverture d'un ticket auprès de MTN pour vérifier la connectivité réseau, puis transmission du retour à Mediasoft.",
                    'La connectivité inter-serveurs reste non opérationnelle : elle bloque le démarrage du pilote.',
                ],
                'synthese' => "Déblocage partiel : la réponse au courrier est arrivée le 20 août et une session de troubleshooting s'est tenue le 18 août. L'anomalie de connectivité est désormais instruite côté opérateur, via un ticket MTN ouvert par Coris Bank.",
            ],

            'OBA-MOBILE' => [
                'avancement' => 66.7, 'taux' => 60.32,
                'realisees' => [
                    ['Réception du retour Sécurité & testing : correctifs applicatifs appliqués sur la version Android', '2026-08-20', ProgressStatus::Termine],
                    ['Mise à disposition de la version Android 2.4.4.19 avec activation du clavier natif', '2026-08-20', ProgressStatus::Termine],
                    ["Transmission de la nouvelle version à l'équipe OBA pour démarrage des tests", '2026-08-20', ProgressStatus::Termine],
                    ["Mécanisme d'interdiction des captures et enregistrements d'écran sur iOS", '2026-08-21', ProgressStatus::EnCours],
                ],
                'a_realiser' => [
                    ['Assurer le suivi de la correction du point iOS', '2026-08-24', ProgressStatus::NonDemarre],
                    ["Obtenir le retour de l'équipe OBA sur les tests de la version Android", '2026-08-27', ProgressStatus::NonDemarre],
                    ['Mettre à jour les CGU', '2026-08-27', ProgressStatus::NonDemarre],
                ],
                'attention' => [
                    'UAT : en attente du retour des tests OBA sur la version Android.',
                    "Version iOS : le mécanisme empêchant les captures et enregistrements d'écran sur les écrans sensibles (mot de passe, PIN, authentification) reste à livrer.",
                ],
                'synthese' => "Le blocage du clavier natif est levé : la version Android 2.4.4.19 est livrée et les correctifs de sécurité sont validés. Le passage en pilote dépend désormais du retour de tests d'Orange Bank et de la finalisation du point iOS.",
            ],

            'VERSUS-NETPRO' => [
                'avancement' => 91.66, 'taux' => 90, 'fin_revisee' => '2026-09-03',
                'realisees' => [
                    ['Suivi post-généralisation', '2026-08-21', ProgressStatus::EnCours],
                ],
                'a_realiser' => [
                    ['Proposer la date de clôture au client (jeudi 03/09/2026)', '2026-08-28', ProgressStatus::NonDemarre],
                ],
                'attention' => [
                    'La date de clôture a de nouveau glissé, du 25 août au 03 septembre 2026.',
                    "Le taux de réalisation n'est toujours pas renseigné dans le dossier du comité.",
                ],
                'synthese' => "Le projet reste en suivi post-généralisation à 91,66 %. La clôture, envisagée au 25 août, est repoussée au 03 septembre : c'est le second report consécutif.",
            ],

            'BDM-KUNKAN' => [
                'avancement' => 31, 'taux' => 35,
                'realisees' => [
                    ["Test fonctionnel de l'interface Web client (13 au 17/08/2026)", '2026-08-17', ProgressStatus::Termine],
                    ['Tests de sécurité (03 au 20/08/2026)', '2026-08-20', ProgressStatus::EnCours],
                    ["Test fonctionnel de l'interface Mobile Client Android (18 au 21/08/2026)", '2026-08-21', ProgressStatus::Termine],
                    ['Parcours fonctionnel avec le panel client', '2026-08-21', ProgressStatus::Termine],
                ],
                'a_realiser' => [
                    ["Test fonctionnel de l'interface Mobile Client iOS (24 au 25/08/2026)", '2026-08-25', ProgressStatus::NonDemarre],
                    ['Terminer la mise en place des API (création client, création compte)', '2026-08-28', ProgressStatus::EnCours],
                    ['Terminer le cadrage', '2026-08-28', ProgressStatus::EnCours],
                    ["Organiser l'atelier Comptable (date à définir)", '2026-08-28', ProgressStatus::NonDemarre],
                ],
                'attention' => [
                    'Les tests Web client, Android et le parcours avec le panel client sont désormais validés.',
                    "L'avancement ne progresse que d'un point (30 % → 31 %) malgré quatre lots de tests validés : la cotation est à revoir.",
                ],
                'synthese' => 'Semaine productive côté tests : Web client, Android et parcours panel sont validés. Seuls les tests iOS restent, prévus les 24 et 25 août. Le cadrage et les API demeurent ouverts.',
            ],

            'BMS-TURAPAY' => [
                'avancement' => 30, 'taux' => 42,
                'realisees' => [
                    ['Mise en place des API et interfaces du lot précédent', '2026-08-18', ProgressStatus::Termine],
                ],
                'a_realiser' => [
                    ["API Gestion CBS : intégration de l'API callback de notification de retrait sans carte", '2026-08-25', ProgressStatus::NonDemarre],
                    ["API Gestion BackOffice : endpoints de consultation et d'enregistrement de la piste d'audit", '2026-08-26', ProgressStatus::NonDemarre],
                    ["Interface BackOffice : piste d'audit, plafonnement et déplafonnement", '2026-08-28', ProgressStatus::NonDemarre],
                    ['Terminer le cadrage', '2026-08-28', ProgressStatus::EnCours],
                ],
                'attention' => [
                    "Planning toujours à valider : aucune date de fin n'est arrêtée depuis le lancement en juillet 2024.",
                    'Aucune progression enregistrée cette semaine : le projet reste à 30 %.',
                ],
                'synthese' => "Semaine sans progression : les travaux d'API prévus du 17 au 21 août n'ont pas démarré et sont reportés. L'absence de planning validé reste le point structurant à traiter.",
            ],

            'BMS-WHATSAPP' => [
                'avancement' => 65, 'taux' => 83,
                'etape' => 'Pilote', 'annotation' => 'V1 — panel restreint en production',
                'realisees' => [
                    ['Ouverture des UAT sur AIF test et tests de sécurité de la V1', '2026-08-18', ProgressStatus::Termine],
                    ['Ouverture à un panel restreint de la V1 en environnement de production', '2026-08-19', ProgressStatus::Termine],
                    ['Fonctionnalités transactionnelles AIP Rest WhatsApp', '2026-08-21', ProgressStatus::EnCours],
                    ['Adaptation du module de facturation', '2026-08-21', ProgressStatus::EnCours],
                ],
                'a_realiser' => [
                    ['V2 — Intégration API Business : endpoints relevé, RIB et extrait', '2026-08-26', ProgressStatus::NonDemarre],
                    ['V2 — Intégration API Business : endpoint de virement interne', '2026-08-28', ProgressStatus::NonDemarre],
                ],
                'attention' => [
                    'Reprise à plein temps des travaux sur le module de facturation et les fonctionnalités additionnelles de la V2.',
                ],
                'synthese' => 'La V1 est ouverte à un panel restreint en production depuis le 19 août, UAT et tests de sécurité validés. Les travaux se reportent sur la facturation et les API Business de la V2.',
            ],

            'BAM-MOBILE' => [
                'avancement' => 93, 'taux' => 64.28,
                'realisees' => [
                    ["Tests d'acceptation utilisateur (UAT)", '2026-08-21', ProgressStatus::EnCours],
                ],
                'a_realiser' => [
                    ['Organiser la séance de parcours final de validation en présentiel (date à confirmer)', '2026-08-26', ProgressStatus::NonDemarre],
                    ['Obtenir la signature du PV de recette provisoire', '2026-08-27', ProgressStatus::NonDemarre],
                    ['Déployer en environnement de préproduction', '2026-08-27', ProgressStatus::NonDemarre],
                    ["Planifier la séance de présentation de l'application au régulateur (AMF)", '2026-08-28', ProgressStatus::NonDemarre],
                ],
                'attention' => [
                    'La date de fin révisée du 17 août 2026 est dépassée sans nouvelle date arrêtée.',
                    'Date de fin à réviser dans l\'attente de la signature du PV de recette et de la date de présentation au régulateur.',
                    'Blocage sur les autorisations du port de communication 4215 dans l\'environnement BAM.',
                    'Le COPIL est convoqué mais sa tenue reste suspendue à la disponibilité du client.',
                ],
                'synthese' => "Aucune avancée cette semaine et l'échéance du 17 août est passée. Le projet est suspendu à quatre actions client qui n'ont toujours pas de date ferme, à commencer par la séance de validation en présentiel.",
            ],

            'BNI-SGIFS' => [
                'avancement' => 70, 'taux' => 64.3,
                'realisees' => [
                    ['Mise à disposition des prérequis indispensables par la BNI', '2026-08-21', ProgressStatus::EnCours],
                ],
                'a_realiser' => [
                    ["Démarrer les travaux d'implémentation du PCB SYCEBNL", '2026-08-28', ProgressStatus::NonDemarre],
                    ['Relancer le sponsor et le client sur les prérequis', '2026-08-25', ProgressStatus::NonDemarre],
                ],
                'attention' => [
                    'La BNI confirme son exigence : intégration complète et préalable de la norme SYCEBNL avant toute mise en production.',
                    'Les prérequis côté banque ne sont toujours pas fournis, malgré la relance du sponsor.',
                    'La date de fin révisée reste à définir.',
                ],
                'synthese' => "Situation inchangée : le démarrage de l'implémentation du PCB SYCEBNL reste bloqué par la mise à disposition des prérequis côté BNI, en attente depuis le recadrage du 04 août.",
            ],

            'BNI-ALTERNATIS' => [
                'avancement' => 33.33, 'taux' => 65,
                'realisees' => [
                    ["Gestion des files d'attente", '2026-08-18', ProgressStatus::Termine],
                    ['Connexion fournisseur', '2026-08-21', ProgressStatus::EnCours],
                    ['Module Mail et PUSH', '2026-08-21', ProgressStatus::EnCours],
                ],
                'a_realiser' => [
                    ['Terminer le module Mail et PUSH', '2026-08-26', ProgressStatus::EnCours],
                    ['Test de connexion des fournisseurs (24 au 28/08/2026)', '2026-08-28', ProgressStatus::NonDemarre],
                    ["Test d'envoi des messages", '2026-08-28', ProgressStatus::NonDemarre],
                ],
                'attention' => [
                    "L'avancement déclaré (33,33 %) reste très inférieur au taux de réalisation (65 %) : la cotation est à arbitrer.",
                    'Le module Mail et PUSH, non prévu la semaine passée, est venu s\'ajouter au reste à faire.',
                ],
                'synthese' => "Le périmètre s'élargit d'un module Mail et PUSH tandis que la connexion fournisseur n'est toujours pas terminée. Les tests de bout en bout sont reportés à la semaine du 24 août.",
            ],
        ];
    }

    /**
     * Applique l'évolution d'une semaine à un projet : chiffres, étape courante
     * et nouvelles activités.
     *
     * @param  array<string, mixed>  $maj
     */
    private function appliquerMiseAJour(Project $project, array $maj): Project
    {
        $project->update(array_filter([
            'avancement_pct' => $maj['avancement'] ?? null,
            'taux_realisation_pct' => $maj['taux'] ?? null,
            'date_fin_revisee' => $maj['fin_revisee'] ?? null,
        ], fn ($valeur) => $valeur !== null));

        $project = $project->fresh(['steps.workflowStep', 'phases.phase']);

        if (isset($maj['etape'])) {
            $this->positionnerEtape($project, $maj['etape'], $maj['annotation'] ?? null);
        }

        $this->ajouterTachesDuProjet($project, $maj);

        return $project->fresh(['tasks', 'milestones', 'deliverables', 'steps.workflowStep']);
    }

    /**
     * Variante de ajouterTaches() qui répartit les activités sur les acteurs
     * déjà rattachés au projet, sans repasser par les clés de définition.
     *
     * @param  array<string, mixed>  $maj
     */
    private function ajouterTachesDuProjet(Project $project, array $maj): void
    {
        $assignables = array_values(array_filter([
            $project->chef_projet_id,
            $project->referent_technique_id,
            $project->back_up_id,
        ]));

        if ($assignables === []) {
            $assignables = [null];
        }

        $rang = 0;

        foreach (['realisees', 'a_realiser'] as $rubrique) {
            foreach ($maj[$rubrique] ?? [] as [$libelle, $date, $statut]) {
                $assignee = $assignables[$rang % count($assignables)];
                $rang++;

                $project->tasks()->updateOrCreate(
                    ['libelle' => $libelle],
                    [
                        'date_echeance' => $date,
                        'date_realisation' => $statut === ProgressStatus::Termine ? $date : null,
                        'statut' => $statut,
                        'assignee_id' => $assignee,
                        'avancement_pct' => match ($statut) {
                            ProgressStatus::Termine => 100,
                            ProgressStatus::EnCours => 50,
                            default => 0,
                        },
                    ],
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $def
     */
    private function creerProjet(array $def, ProjectProvisioner $provisioner): Project
    {
        $project = Project::updateOrCreate(['code' => $def['code']], [
            'nom' => $def['nom'],
            'description' => $def['description'],
            'client_id' => $this->clients[$def['client']]->id,
            'type_projet' => $def['type'],
            'categorie' => $def['categorie'],
            'ordre_comite' => $def['ordre'],
            'pays_domiciliation' => $def['pays'],
            'statut' => ProjectStatus::EnCours,
            'date_debut' => $def['debut'],
            'date_fin_initiale' => $def['fin_initiale'],
            'date_fin_revisee' => $def['fin_revisee'],
            'avancement_pct' => $def['avancement'],
            'taux_realisation_pct' => $def['taux'],
            'lien_planning' => $def['lien'] ?? null,
            'chef_projet_id' => $this->users[$def['cp']]->id,
            'back_up_id' => $def['backup'] ? $this->users[$def['backup']]->id : null,
            'sponsor_id' => $def['sponsor'] ? $this->users[$def['sponsor']]->id : null,
            'referent_technique_id' => $def['reftech'] ? $this->users[$def['reftech']]->id : null,
        ]);

        $provisioner->provisionner($project);
        $project = $project->fresh(['phases.phase', 'steps.workflowStep', 'deliverables']);

        foreach ($def['testeurs'] as $cle) {
            $project->members()->firstOrCreate(
                ['user_id' => $this->users[$cle]->id, 'role' => MemberRole::Testeur->value],
                ['allocation_pct' => 60],
            );
        }

        $this->positionnerEtape($project, $def['etape'], $def['annotation'] ?? null);
        $this->ajouterTaches($project, $def);
        $this->ajouterJalons($project, $def['jalons'] ?? []);
        $this->avancerPhases($project, $def['phase']);
        $this->livrerJusqua($project, $def['livres']);

        return $project->fresh(['tasks', 'milestones', 'deliverables', 'steps.workflowStep']);
    }

    private function positionnerEtape(Project $project, string $libelle, ?string $annotation): void
    {
        $etape = WorkflowStep::forType($project->type_projet)->where('libelle', $libelle)->first();

        if (! $etape) {
            return;
        }

        $project->update(['workflow_step_id' => $etape->id, 'phase_id' => $etape->phase_id]);

        foreach ($project->steps as $projectStep) {
            $projectStep->update([
                'statut' => match (true) {
                    $projectStep->ordre < $etape->ordre => ProgressStatus::Termine,
                    $projectStep->ordre === $etape->ordre => ProgressStatus::EnCours,
                    default => ProgressStatus::NonDemarre,
                },
                'annotation' => $projectStep->ordre === $etape->ordre ? $annotation : null,
            ]);
        }
    }

    /**
     * Les activités du dossier deviennent des tâches ; le flash report les reprend ensuite.
     *
     * @param  array<string, mixed>  $def
     */
    private function ajouterTaches(Project $project, array $def): void
    {
        // Le chef de projet est toujours assignable ; référent et back-up complètent la rotation.
        $assignables = [$def['cp']];

        foreach ([$def['reftech'] ?? null, $def['backup'] ?? null] as $cle) {
            if ($cle !== null) {
                $assignables[] = $cle;
            }
        }

        $rang = 0;

        foreach (['realisees', 'a_realiser'] as $rubrique) {
            foreach ($def[$rubrique] as [$libelle, $date, $statut]) {
                $assignee = $this->users[$assignables[$rang % count($assignables)]];
                $rang++;

                $project->tasks()->updateOrCreate(
                    ['libelle' => $libelle],
                    [
                        'date_echeance' => $date,
                        // Seules les tâches closes portent une date de réalisation.
                        'date_realisation' => $statut === ProgressStatus::Termine ? $date : null,
                        'statut' => $statut,
                        'assignee_id' => $assignee->id,
                        'avancement_pct' => match ($statut) {
                            ProgressStatus::Termine => 100,
                            ProgressStatus::EnCours => 50,
                            default => 0,
                        },
                    ],
                );
            }
        }
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: string|null, 3: ProgressStatus, 4: bool, 5: string|null}>  $jalons
     */
    private function ajouterJalons(Project $project, array $jalons): void
    {
        foreach ($jalons as [$libelle, $prevue, $reelle, $statut, $critique, $instance]) {
            $project->milestones()->updateOrCreate(
                ['libelle' => $libelle],
                [
                    'date_prevue' => $prevue,
                    'date_prevue_initiale' => $prevue,
                    'date_reelle' => $reelle,
                    'statut' => $statut,
                    'critique' => $critique,
                    'instance' => $instance,
                ],
            );
        }
    }

    /**
     * Marque comme terminées les phases MPM antérieures à la phase courante,
     * et répartit des dates plausibles sur la durée du projet.
     */
    private function avancerPhases(Project $project, string $codePhaseCourante): void
    {
        $project->loadMissing('phases.phase');

        $courante = $project->phases->first(fn ($p) => $p->phase->code === $codePhaseCourante);

        if (! $courante || ! $project->date_debut) {
            return;
        }

        $fin = $project->dateFinEffective() ?? $project->date_debut->addYear();
        $total = max(8, (int) $project->date_debut->diffInDays($fin));
        $pas = (int) floor($total / max(1, $project->phases->count()));

        foreach ($project->phases as $index => $phase) {
            $debut = $project->date_debut->addDays($pas * $index);
            $finPhase = $debut->addDays(max(1, $pas - 1));

            $statut = match (true) {
                $phase->ordre < $courante->ordre => ProgressStatus::Termine,
                $phase->ordre === $courante->ordre => ProgressStatus::EnCours,
                default => ProgressStatus::NonDemarre,
            };

            $phase->update([
                'date_debut_prevue' => $debut,
                'date_fin_prevue' => $finPhase,
                'date_debut_reelle' => $statut === ProgressStatus::NonDemarre ? null : $debut,
                'date_fin_reelle' => $statut === ProgressStatus::Termine ? $finPhase : null,
                'statut' => $statut,
                'avancement_pct' => match ($statut) {
                    ProgressStatus::Termine => 100,
                    ProgressStatus::EnCours => 50,
                    default => 0,
                },
            ]);
        }
    }

    /**
     * Valide les livrables des phases situées jusqu'au code fourni inclus.
     */
    private function livrerJusqua(Project $project, string $codePhase): void
    {
        $project->loadMissing(['deliverables.phase', 'phases.phase']);

        $limite = $project->phases->first(fn ($p) => $p->phase->code === $codePhase)?->phase->ordre;

        if (! $limite) {
            return;
        }

        foreach ($project->deliverables as $livrable) {
            if ($livrable->phase->ordre > $limite) {
                continue;
            }

            $livrable->update([
                'statut' => DeliverableStatus::Valide,
                'date_livraison' => $project->date_debut?->addDays(20 * $livrable->phase->ordre),
                'version' => '1.0',
            ]);
        }
    }

    /**
     * Génère le flash report de la semaine puis y ajoute les points d'attention
     * et la synthèse rédigés par le chef de projet, que le calcul ne peut pas deviner.
     *
     * @param  array<string, mixed>  $def
     */
    private function redigerFlashReport(
        Project $project,
        array $def,
        FlashReportBuilder $builder,
        Carbon $semaine,
    ): void {
        $rapport = $builder->preparer($project, $semaine, $project->chef_projet_id);

        foreach ($def['attention'] ?? [] as $point) {
            $dejaPresent = $rapport->items()
                ->where('type', FlashItemType::Attention->value)
                ->where('libelle', $point)
                ->exists();

            if (! $dejaPresent) {
                $builder->ajouterLigne($rapport, FlashItemType::Attention, $point);
            }
        }

        $rapport->update(['synthese' => $def['synthese'] ?? null]);
    }
}
