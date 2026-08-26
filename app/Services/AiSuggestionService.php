<?php

namespace App\Services;

use App\Exceptions\SuggestionIndisponible;
use App\Models\FlashReport;
use App\Models\Project;
use App\Models\Task;
use App\Support\Alert;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Assistant de rédaction.
 *
 * Il ne décide de rien et n'écrit rien en base : il propose des formulations
 * à partir des données déjà saisies — avancement, dérives, tâches de la
 * semaine — que le chef de projet reprend, corrige ou ignore. Le texte retenu
 * n'est enregistré que par l'action habituelle de l'écran.
 */
class AiSuggestionService
{
    private const CONSIGNE = <<<'TXT'
        Tu rédiges pour le suivi de projet d'une direction des projets, en français
        professionnel, à la manière d'un flash report présenté en comité.

        Règles de rédaction :
        - phrases courtes, factuelles, au ton neutre ; pas de superlatif, pas de
          promesse, pas de tournure commerciale ;
        - n'invente aucun chiffre, aucune date, aucun nom qui ne soit pas dans le
          contexte fourni ; si une information manque, ne la remplace pas ;
        - reste dans le vocabulaire du contexte (phases, livrables, jalons).

        Réponds uniquement par un tableau JSON de chaînes, sans texte autour :
        ["proposition 1", "proposition 2"]
        TXT;

    public function disponible(): bool
    {
        return (bool) config('ia.active') && filled(config('ia.cle'));
    }

    /**
     * Synthèse hebdomadaire d'un flash report : où en est le projet, ce qui
     * s'est joué cette semaine, ce qui appelle une décision.
     *
     * @return array<int, string>
     */
    public function syntheseFlashReport(FlashReport $rapport, ?string $brouillon = null): array
    {
        $rapport->loadMissing(['project', 'items']);

        $contexte = $this->contexteProjet($rapport->project);
        $contexte[] = 'Semaine du rapport : '.$rapport->periodeLabel();
        $contexte[] = 'Avancement déclaré : '.round($rapport->avancement_pct).' %';
        $contexte[] = 'Taux de réalisation : '.round($rapport->taux_realisation_pct).' %';

        foreach ($rapport->items->groupBy(fn ($item) => $item->type->shortLabel()) as $rubrique => $items) {
            $contexte[] = $rubrique.' : '.$items->pluck('libelle')->take(12)->implode(' | ');
        }

        return $this->demander(
            'Rédige la synthèse hebdomadaire de ce projet, en trois à cinq phrases : situation, faits marquants de la semaine, point de vigilance. Propose deux variantes.',
            $contexte,
            $brouillon,
        );
    }

    /**
     * Formulation d'un point d'attention à porter au comité.
     *
     * @return array<int, string>
     */
    public function pointDAttention(Project $project, ?string $brouillon = null): array
    {
        return $this->demander(
            "Formule des points d'attention à porter au comité : le fait constaté, sa conséquence sur le projet, et l'arbitrage ou l'action attendue. Une phrase ou deux par proposition, trois propositions distinctes.",
            $this->contexteProjet($project),
            $brouillon,
        );
    }

    /**
     * Description d'une carte du tableau : ce qu'il y a à faire, et à quoi on
     * reconnaîtra que c'est fait.
     *
     * @return array<int, string>
     */
    public function descriptionTache(Task $tache, ?string $brouillon = null): array
    {
        $tache->loadMissing(['project', 'projectPhase.phase', 'assignee']);

        $contexte = $this->contexteProjet($tache->project);
        $contexte[] = 'Tâche : '.$tache->libelle;
        $contexte[] = 'Statut : '.$tache->statut->label().' ('.round($tache->avancement_pct).' %)';

        if ($tache->projectPhase?->phase) {
            $contexte[] = 'Phase MPM de la tâche : '.$tache->projectPhase->phase->nom;
        }

        if ($tache->assignee) {
            $contexte[] = 'Assignée à : '.$tache->assignee->name
                .($tache->assignee->equipe ? ' ('.$tache->assignee->equipe.')' : '');
        }

        if ($tache->date_echeance) {
            $contexte[] = 'Échéance : '.$tache->date_echeance->format('d/m/Y');
        }

        return $this->demander(
            "Rédige la description de cette tâche : l'objectif, le travail attendu, et le critère qui permettra de la déclarer terminée. Trois lignes au plus, deux propositions.",
            $contexte,
            $brouillon,
        );
    }

    /**
     * Éléments du projet communs à toutes les demandes.
     *
     * @return array<int, string>
     */
    private function contexteProjet(Project $project): array
    {
        $project->loadMissing(['client', 'phase', 'chefProjet', 'milestones', 'tasks', 'deliverables']);

        $contexte = [
            'Projet : '.$project->nom.' ('.$project->code.')',
            'Client : '.$project->client->displayName(),
            'Type : '.$project->type_projet->label(),
            'Statut : '.$project->statut->label(),
            'Avancement global : '.round($project->avancement_pct).' %',
        ];

        if ($project->phase) {
            $contexte[] = 'Phase MPM en cours : '.$project->phase->nom;
        }

        if ($project->date_fin_revisee) {
            $contexte[] = 'Fin prévue : '.$project->date_fin_revisee->format('d/m/Y');
        }

        $sante = app(ProjectHealthService::class)->diagnostiquer($project);
        $contexte[] = 'Santé : '.$sante->statut->label();

        $derives = $sante->alertes->map(fn (Alert $alerte) => $alerte->texteComplet())->take(8);

        if ($derives->isNotEmpty()) {
            $contexte[] = 'Dérives détectées : '.$derives->implode(' | ');
        }

        return $contexte;
    }

    /**
     * @param  array<int, string>  $contexte
     * @return array<int, string>
     */
    private function demander(string $demande, array $contexte, ?string $brouillon = null): array
    {
        if (! $this->disponible()) {
            throw SuggestionIndisponible::nonConfigure();
        }

        $message = "Contexte :\n- ".implode("\n- ", $contexte)."\n\nDemande :\n".$demande;

        if (filled($brouillon)) {
            $message .= "\n\nBrouillon à reprendre, dont il faut garder le sens :\n".Str::limit((string) $brouillon, 2000);
        }

        return $this->extraire($this->appeler($message));
    }

    private function appeler(string $message): string
    {
        try {
            $reponse = Http::withHeaders([
                'x-api-key' => (string) config('ia.cle'),
                'anthropic-version' => (string) config('ia.version'),
            ])
                ->timeout((int) config('ia.timeout'))
                ->post((string) config('ia.url'), $this->corpsDeLaDemande($message));
        } catch (ConnectionException $e) {
            Log::warning('Assistant de rédaction injoignable.', ['exception' => $e->getMessage()]);

            throw SuggestionIndisponible::injoignable();
        }

        if ($reponse->failed()) {
            Log::warning('Assistant de rédaction en erreur.', [
                'statut' => $reponse->status(),
                'corps' => Str::limit($reponse->body(), 500),
            ]);

            throw SuggestionIndisponible::injoignable();
        }

        $blocs = $reponse->json('content');

        // Une réponse qui ne porte pas de blocs de contenu n'est pas exploitable.
        if (! is_array($blocs)) {
            throw SuggestionIndisponible::reponseVide();
        }

        return collect($blocs)
            ->where('type', 'text')
            ->pluck('text')
            ->implode("\n");
    }

    /**
     * Corps de la requête. L'effort de raisonnement n'est envoyé que s'il est
     * configuré : les générations antérieures de modèles le rejettent, et une
     * installation qui pointe vers l'une d'elles doit rester fonctionnelle.
     *
     * @return array<string, mixed>
     */
    private function corpsDeLaDemande(string $message): array
    {
        $corps = [
            'model' => (string) config('ia.modele'),
            'max_tokens' => (int) config('ia.max_tokens'),
            'system' => self::CONSIGNE,
            'messages' => [['role' => 'user', 'content' => $message]],
        ];

        if (filled(config('ia.effort'))) {
            $corps['output_config'] = ['effort' => (string) config('ia.effort')];
        }

        return $corps;
    }

    /**
     * La consigne demande un tableau JSON ; on ne s'y fie pas pour autant, et
     * on retombe sur un découpage ligne à ligne si la réponse dérape.
     *
     * @return array<int, string>
     */
    private function extraire(string $texte): array
    {
        $texte = trim($texte);

        if (preg_match('/\[.*\]/s', $texte, $trouve) === 1) {
            $decode = json_decode($trouve[0], true);

            if (is_array($decode)) {
                $propositions = collect($decode)
                    ->filter(fn ($ligne) => is_string($ligne) && trim($ligne) !== '')
                    ->map(fn ($ligne) => trim((string) $ligne))
                    ->values();

                if ($propositions->isNotEmpty()) {
                    return $propositions->all();
                }
            }
        }

        $propositions = collect(preg_split('/\R{2,}/', $texte) ?: [])
            ->map(fn (string $ligne) => trim(ltrim($ligne, "-*• \t")))
            ->filter()
            ->values();

        if ($propositions->isEmpty()) {
            throw SuggestionIndisponible::reponseVide();
        }

        return $propositions->all();
    }
}
