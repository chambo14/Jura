<?php

namespace App\Enums;

enum MemberRole: string
{
    case ChefProjet = 'chef_projet';
    case BackUp = 'back_up';
    case Sponsor = 'sponsor';
    case ReferentTechnique = 'referent_technique';
    case Testeur = 'testeur';
    case Developpeur = 'developpeur';
    case Integrateur = 'integrateur';
    case Formateur = 'formateur';
    case Contributeur = 'contributeur';
    case Autre = 'autre';

    public function label(): string
    {
        return match ($this) {
            self::ChefProjet => 'Chef de projet',
            self::BackUp => 'Back Up',
            self::Sponsor => 'Sponsor',
            self::ReferentTechnique => 'Référent technique',
            self::Testeur => 'Testeur',
            self::Developpeur => 'Développeur',
            self::Integrateur => 'Intégrateur',
            self::Formateur => 'Formateur',
            self::Contributeur => 'Contributeur',
            self::Autre => 'Autre',
        };
    }

    /**
     * Part d'un temps plein qu'un rôle consomme lorsqu'il est affecté à 100 %.
     *
     * Un sponsor suit un projet en comité, il ne l'exécute pas : le compter
     * comme un développeur donnerait des plans de charge à 600 %.
     */
    public function poids(): int
    {
        return match ($this) {
            self::Sponsor => 5,
            self::BackUp => 15,
            self::ReferentTechnique => 25,
            self::Testeur => 30,
            self::Formateur => 30,
            self::ChefProjet => 40,
            self::Integrateur => 70,
            self::Developpeur => 80,
            self::Contributeur, self::Autre => 50,
        };
    }

    /**
     * Rôle attribué automatiquement quand une tâche est déléguée à quelqu'un
     * qui n'était pas encore rattaché au projet.
     */
    public static function parDelegation(): self
    {
        return self::Contributeur;
    }

    /**
     * Roles rendered in the "PILOTAGE" block of the flash report, in display order.
     *
     * @return array<int, self>
     */
    public static function pilotage(): array
    {
        return [self::ChefProjet, self::BackUp, self::Sponsor, self::ReferentTechnique, self::Testeur];
    }

    /**
     * Reconnaît une fonction dans un intitulé de poste saisi librement.
     *
     * Les comptes existants portent leur fonction dans le champ « poste », en
     * toutes lettres et parfois au féminin. Cette lecture sert à les reprendre
     * une fois, à la mise en service du champ structuré ; ensuite, c'est ce
     * dernier qui fait foi.
     */
    public static function depuisUnPoste(?string $poste): ?self
    {
        $normalise = self::sansAccent($poste ?? '');

        if ($normalise === '') {
            return null;
        }

        foreach (self::cases() as $cas) {
            if ($cas === self::Autre) {
                continue;
            }

            $libelle = self::sansAccent($cas->label());

            // « Testeuse » comme « Testeur », « Chef de projet adjoint » comme
            // « Chef de projet » : on retient la racine du libellé.
            if (str_starts_with($normalise, rtrim($libelle, 'r')) || str_contains($normalise, $libelle)) {
                return $cas;
            }
        }

        return null;
    }

    private static function sansAccent(string $texte): string
    {
        $translitere = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texte);

        return mb_strtolower(trim($translitere === false ? $texte : $translitere));
    }

    /**
     * Fonctions qu'un compte peut porter, dans l'ordre du bloc « Pilotage »
     * puis des rôles d'exécution.
     *
     * @return array<int, self>
     */
    public static function fonctions(): array
    {
        return self::cases();
    }

    /**
     * Profils d'accès dont les titulaires peuvent tenir ce rôle sur un projet.
     *
     * Le profil d'un compte dit ses droits d'accès, pas son métier : filtrer
     * dessus faisait apparaître les testeurs parmi les référents techniques,
     * tous étant « membres d'équipe ». C'est la fonction du compte qui décide.
     *
     * Un back-up est un chef de projet qui supplée : les deux fonctions
     * conviennent. Ailleurs, la règle est stricte — un référent technique est
     * un référent technique.
     *
     * Un tableau vide vaut absence de restriction : les rôles d'exécution
     * s'attribuent sans filtre depuis l'onglet Ressources.
     *
     * @return array<int, string>
     */
    public function fonctionsEligibles(): array
    {
        return match ($this) {
            self::ChefProjet => [self::ChefProjet->value],
            self::BackUp => [self::BackUp->value, self::ChefProjet->value],
            self::Sponsor => [self::Sponsor->value],
            self::ReferentTechnique => [self::ReferentTechnique->value],
            default => [],
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ChefProjet => 'blue',
            self::BackUp => 'sky',
            self::Sponsor => 'amber',
            self::ReferentTechnique => 'violet',
            self::Testeur => 'teal',
            default => 'zinc',
        };
    }
}
