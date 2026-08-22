<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Crée le premier compte capable d'administrer l'application.
 *
 * L'inscription publique est désactivée : un compte ne peut naître que de la
 * main d'un utilisateur qui gère déjà les utilisateurs. Sur une installation
 * neuve, personne ne remplit cette condition — d'où cette commande, seul point
 * d'entrée pour amorcer une mise en service sans passer par le jeu de
 * démonstration, dont tous les comptes partagent le mot de passe « password ».
 *
 * L'adresse est marquée vérifiée d'emblée : la vérification par e-mail est
 * exigée par toutes les pages, et un serveur fraîchement installé n'a
 * généralement pas encore de service d'envoi configuré.
 */
class CreerAdministrateurCommand extends Command
{
    /**
     * Longueur minimale exigée, au-delà des huit caractères habituels : ce
     * compte voit l'intégralité du portefeuille.
     */
    private const LONGUEUR_MINIMALE = 12;

    protected $signature = 'mpm:creer-administrateur
        {--nom= : Nom complet du collaborateur}
        {--email= : Adresse de connexion}
        {--poste= : Intitulé de poste, facultatif}';

    protected $description = "Crée un compte Direction, seul moyen d'amorcer une installation neuve";

    public function handle(): int
    {
        $profil = Profile::where('code', UserRole::Direction->value)->first();

        if (! $profil) {
            $this->components->error(
                'Le profil « Direction » est introuvable. Lancez d\'abord : php artisan db:seed --class=MpmReferentialSeeder'
            );

            return self::FAILURE;
        }

        $nom = $this->option('nom') ?: text('Nom complet', required: true);
        $email = $this->option('email') ?: text('Adresse e-mail', required: true);

        $validation = Validator::make(
            ['nom' => $nom, 'email' => $email],
            [
                'nom' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            ],
        );

        if ($validation->fails()) {
            foreach ($validation->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $motDePasse = $this->motDePasse();

        if ($motDePasse === null) {
            return self::FAILURE;
        }

        $utilisateur = User::create([
            'name' => $nom,
            'email' => $email,
            'password' => Hash::make($motDePasse),
            'poste' => $this->option('poste') ?: 'Direction des Projets',
            'profile_id' => $profil->id,
            'actif' => true,
            'email_verified_at' => now(),
        ]);

        $this->components->info("Compte Direction créé pour {$utilisateur->email}.");
        $this->components->warn('Connectez-vous puis créez les autres comptes depuis l\'écran Utilisateurs.');

        return self::SUCCESS;
    }

    /**
     * Mot de passe du compte, saisi au clavier ou lu dans l'environnement.
     *
     * Il n'est jamais accepté en option de ligne de commande : il figurerait
     * alors dans l'historique du shell et dans la liste des processus, lisible
     * par les autres comptes de la machine. La variable MPM_ADMIN_PASSWORD
     * existe pour les installations automatisées, où aucun terminal n'est
     * disponible ; elle doit être fournie par le gestionnaire de secrets de
     * l'hébergeur, pas écrite dans une commande.
     */
    private function motDePasse(): ?string
    {
        if ($depuisEnvironnement = getenv('MPM_ADMIN_PASSWORD')) {
            if (strlen($depuisEnvironnement) < self::LONGUEUR_MINIMALE) {
                $this->components->error('MPM_ADMIN_PASSWORD doit compter au moins '.self::LONGUEUR_MINIMALE.' caractères.');

                return null;
            }

            return $depuisEnvironnement;
        }

        $saisi = password(
            label: 'Mot de passe',
            required: true,
            validate: fn (string $valeur) => strlen($valeur) < self::LONGUEUR_MINIMALE
                ? 'Le mot de passe doit compter au moins '.self::LONGUEUR_MINIMALE.' caractères.'
                : null,
        );

        if ($saisi !== password(label: 'Confirmez le mot de passe', required: true)) {
            $this->components->error('Les deux saisies diffèrent.');

            return null;
        }

        return $saisi;
    }
}
