<?php

namespace Database\Seeders;

use App\Enums\ProjectType;
use App\Enums\UserRole;
use App\Models\DeliverableType;
use App\Models\Phase;
use App\Models\Profile;
use App\Models\WorkflowStep;
use Illuminate\Database\Seeder;

/**
 * Référentiel issu du support de formation MPM (Méthodologie Projet MEDIASOFT) :
 * les 8 phases, les livrables types attendus par phase avec leur entité responsable,
 * et le bandeau d'avancement propre à chaque type de projet.
 *
 * Ce seeder est idempotent : il peut être rejoué après une évolution du référentiel.
 */
class MpmReferentialSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedProfiles();

        $phases = $this->seedPhases();
        $this->seedDeliverableTypes($phases);
        $this->seedWorkflowSteps($phases);
    }

    /**
     * Profils d'accès livrés avec l'application. Marqués « système », ils ne sont
     * pas supprimables ; leurs droits restent ajustables par la Direction.
     * Un rejeu ne réécrit pas les droits, pour ne pas défaire un réglage local.
     */
    private function seedProfiles(): void
    {
        foreach (UserRole::cases() as $role) {
            $existant = Profile::where('code', $role->value)->first();

            if ($existant) {
                $existant->update(['systeme' => true]);

                continue;
            }

            Profile::create([
                'code' => $role->value,
                'nom' => $role->label(),
                'description' => $role->description(),
                'couleur' => $role->color(),
                'ordre' => $role->ordre(),
                'systeme' => true,
                ...$role->permissions(),
            ]);
        }
    }

    /**
     * @return array<string, Phase>
     */
    private function seedPhases(): array
    {
        $definitions = [
            ['OPPORTUNITE', 'Opportunité', 'Qualification du besoin et décision GO/NOGO projet.', 'sky'],
            ['CADRAGE', 'Cadrage', 'Ateliers de cadrage, kick-off et contractualisation du périmètre.', 'cyan'],
            ['CONCEPTION', 'Conception', 'Spécifications fonctionnelles, techniques et architecture.', 'teal'],
            ['REALISATION', 'Réalisation', 'Développements, paramétrage et tests unitaires.', 'emerald'],
            ['QUALIFICATION', 'Qualification', 'Recette interne, UAT client, tests sécurité et formation.', 'amber'],
            ['DEPLOIEMENT', 'Mise en production', 'Chronogramme de MEP et bascule en production.', 'orange'],
            ['EXECUTION', 'Exécution', 'Pilote, stabilisation et généralisation de la solution.', 'violet'],
            ['CLOTURE', 'Clôture', 'Bilan projet et réunion de clôture.', 'purple'],
        ];

        $phases = [];

        foreach ($definitions as $ordre => [$code, $nom, $description, $couleur]) {
            $phases[$code] = Phase::updateOrCreate(
                ['code' => $code],
                ['nom' => $nom, 'description' => $description, 'ordre' => $ordre + 1, 'couleur' => $couleur],
            );
        }

        return $phases;
    }

    /**
     * @param  array<string, Phase>  $phases
     */
    private function seedDeliverableTypes(array $phases): void
    {
        // [phase, nom, responsable, obligatoire, types de projet concernés (null = tous)]
        $definitions = [
            ['OPPORTUNITE', "Formulaire d'expression de besoin (EDB)", 'Demandeur', true, null],
            ['OPPORTUNITE', "Note d'opportunité", 'Direction MEDIALOGIC', true, null],
            ['OPPORTUNITE', 'Business Case', 'Direction MEDIALOGIC', false, null],
            ['OPPORTUNITE', 'Charte de projet', 'Direction MEDIALOGIC', true, null],

            ['CADRAGE', 'Dossier de cadrage', 'Projet', true, null],
            ['CADRAGE', 'Cahier des charges', 'Projet', true, null],
            ['CADRAGE', 'Plan Projet', 'Projet', true, null],
            ['CADRAGE', 'Planning détaillé', 'Projet', true, null],
            ['CADRAGE', 'Support réunion de lancement (Kick Off)', 'Projet', true, null],
            ['CADRAGE', 'Cartographie des exigences', 'Projet', true, null],
            ['CADRAGE', 'Questionnaire sécurité', 'Projet', true, null],
            ['CADRAGE', 'Stratégie de test', 'Testing Factory', true, null],
            ['CADRAGE', 'Plan de conduite du changement', 'Projet', false, null],
            ['CADRAGE', 'Plan de migration des données', 'Projet', false, ['deploiement', 'integration']],

            ['CONCEPTION', 'Spécifications fonctionnelles', 'Développement', true, null],
            ['CONCEPTION', 'Spécifications techniques', 'Développement', true, null],
            ['CONCEPTION', "Dossier d'architecture", 'Développement', true, null],

            ['REALISATION', 'Cahier des tests unitaires', 'Développement', true, null],
            ['REALISATION', "Lien d'accès à la plateforme", 'Développement', true, null],
            ['REALISATION', 'Guide de paramétrage', 'Développement', true, null],

            ['QUALIFICATION', 'Cahier de recette', 'Testing Factory', true, null],
            ['QUALIFICATION', 'Journal des remontées', 'Testing Factory', true, null],
            ['QUALIFICATION', 'PV de recette Testing Factory', 'Testing Factory', true, null],
            ['QUALIFICATION', 'Analyse des écarts', 'Projet', false, null],
            ['QUALIFICATION', "PV d'arbitrage des écarts", 'Projet', false, null],
            ['QUALIFICATION', 'PV de recette provisoire', 'Client', true, null],
            ['QUALIFICATION', 'PV de test sécurité', 'Testing Factory', true, null],
            ['QUALIFICATION', 'Guide utilisateur', 'Projet', true, null],
            ['QUALIFICATION', 'Plan de formation', 'Projet', true, null],
            ['QUALIFICATION', "Guide d'exploitation", 'Support & RC', true, null],
            ['QUALIFICATION', 'Checklist de conformité', 'Projet', true, null],

            ['DEPLOIEMENT', 'Chronogramme de MEP', 'Intégration', true, null],
            ['DEPLOIEMENT', 'PV de recette définitif', 'Client', true, null],

            ['EXECUTION', 'Journal des remontées en stabilisation', 'Support & RC', true, null],

            ['CLOTURE', 'Bilan projet', 'Projet', true, null],
        ];

        $ordres = [];

        foreach ($definitions as [$code, $nom, $responsable, $obligatoire, $types]) {
            $ordres[$code] = ($ordres[$code] ?? 0) + 1;

            DeliverableType::updateOrCreate(
                ['phase_id' => $phases[$code]->id, 'nom' => $nom],
                [
                    'responsable' => $responsable,
                    'obligatoire' => $obligatoire,
                    'types_projet' => $types,
                    'ordre' => $ordres[$code],
                ],
            );
        }
    }

    /**
     * Bandeau "PHASE ACTUELLE DU PROJET" tel qu'il figure sur les slides de flash report.
     *
     * @param  array<string, Phase>  $phases
     */
    private function seedWorkflowSteps(array $phases): void
    {
        $sequences = [
            ProjectType::Deploiement->value => [
                ['Lancement', 'OPPORTUNITE'],
                ['Cadrage', 'CADRAGE'],
                ["Mise en place de l'infrastructure de tests", 'CADRAGE'],
                ['Développement', 'REALISATION'],
                ['Déploiement en environnement de tests', 'REALISATION'],
                ['Tests fonctionnels', 'QUALIFICATION'],
                ["Test d'acceptation utilisateur — UAT", 'QUALIFICATION'],
                ['Formation du panel et des formateurs', 'QUALIFICATION'],
                ['Déploiement en environnement de production', 'DEPLOIEMENT'],
                ['Pilote', 'EXECUTION'],
                ['Généralisation', 'EXECUTION'],
                ['Lancement officiel', 'EXECUTION'],
                ['Clôture', 'CLOTURE'],
            ],
            ProjectType::Integration->value => [
                ['Lancement', 'OPPORTUNITE'],
                ['Cadrage', 'CADRAGE'],
                ["Mise en place de l'infrastructure de tests", 'CADRAGE'],
                ['Adaptation', 'REALISATION'],
                ['Déploiement en environnement de tests', 'REALISATION'],
                ['Tests fonctionnels', 'QUALIFICATION'],
                ["Mise en place de l'infrastructure de production", 'DEPLOIEMENT'],
                ["Test d'acceptation utilisateur — UAT", 'QUALIFICATION'],
                ['Formation du panel et des formateurs', 'QUALIFICATION'],
                ['Présentation et parcours avec le client', 'QUALIFICATION'],
                ['Déploiement en environnement de production', 'DEPLOIEMENT'],
                ['Pilote', 'EXECUTION'],
                ['Généralisation', 'EXECUTION'],
                ['Clôture', 'CLOTURE'],
            ],
            ProjectType::Developpement->value => [
                ['Lancement', 'OPPORTUNITE'],
                ['Cadrage', 'CADRAGE'],
                ['Conception', 'CONCEPTION'],
                ['Développement', 'REALISATION'],
                ['Tests unitaires', 'REALISATION'],
                ["Mise en place de l'infrastructure de tests", 'REALISATION'],
                ['Déploiement en environnement de tests', 'REALISATION'],
                ['Tests fonctionnels', 'QUALIFICATION'],
                ["Test d'acceptation utilisateur — UAT", 'QUALIFICATION'],
                ['Formation du panel et des formateurs', 'QUALIFICATION'],
                ['Déploiement en environnement de production', 'DEPLOIEMENT'],
                ['Pilote', 'EXECUTION'],
                ['Généralisation', 'EXECUTION'],
                ['Clôture', 'CLOTURE'],
            ],
        ];

        foreach ($sequences as $type => $etapes) {
            foreach ($etapes as $index => [$libelle, $phaseCode]) {
                WorkflowStep::updateOrCreate(
                    ['type_projet' => $type, 'ordre' => $index + 1],
                    ['libelle' => $libelle, 'phase_id' => $phases[$phaseCode]->id],
                );
            }
        }
    }
}
