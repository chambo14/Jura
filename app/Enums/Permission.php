<?php

namespace App\Enums;

/**
 * Droits attribuables à un profil. Chaque valeur correspond à une colonne
 * booléenne de la table `profiles`.
 */
enum Permission: string
{
    case VoitToutLePortefeuille = 'voit_tout_le_portefeuille';
    case CreeProjet = 'cree_projet';
    case PiloteProjet = 'pilote_projet';
    case Contribue = 'contribue';
    case RedigeFlashReport = 'redige_flash_report';
    case GereUtilisateurs = 'gere_utilisateurs';

    public function label(): string
    {
        return match ($this) {
            self::VoitToutLePortefeuille => 'Voir tout le portefeuille',
            self::CreeProjet => 'Créer des projets',
            self::PiloteProjet => 'Piloter un projet',
            self::Contribue => 'Contribuer au quotidien',
            self::RedigeFlashReport => 'Rédiger les flash reports',
            self::GereUtilisateurs => 'Administrer comptes et profils',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::VoitToutLePortefeuille => "Accède à tous les projets, et non aux seuls projets d'affectation.",
            self::CreeProjet => 'Peut ouvrir un nouveau projet et dérouler le référentiel MPM.',
            self::PiloteProjet => 'Modifie le cadrage : dates, périmètre, équipe, jalons et phases.',
            self::Contribue => 'Met à jour les tâches et le statut des livrables.',
            self::RedigeFlashReport => 'Prépare, modifie et publie le flash report hebdomadaire.',
            self::GereUtilisateurs => 'Crée les comptes, attribue les profils et définit leurs droits.',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function codes(): array
    {
        return array_map(fn (self $permission) => $permission->value, self::cases());
    }
}
