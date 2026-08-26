<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Assistant de rédaction
    |--------------------------------------------------------------------------
    |
    | L'application propose des formulations : synthèse de flash report, point
    | d'attention, description de tâche. Sans clé renseignée, la fonction est
    | simplement absente de l'interface — l'application marche à l'identique.
    |
    */

    'active' => env('IA_ACTIVE', true),

    'cle' => env('ANTHROPIC_API_KEY'),

    'url' => env('IA_URL', 'https://api.anthropic.com/v1/messages'),

    'modele' => env('IA_MODELE', 'claude-opus-5'),

    /*
     * Effort de raisonnement. Reformuler quelques phrases à partir d'un
     * contexte déjà structuré ne demande pas de longues délibérations :
     * « low » suffit, coûte moins cher et répond plus vite.
     *
     * À vider si vous pointez IA_MODELE vers un modèle antérieur : les
     * générations plus anciennes rejettent ce paramètre.
     */
    'effort' => env('IA_EFFORT', 'low'),

    'version' => env('IA_VERSION', '2023-06-01'),

    /*
     * Longueur de la réponse. Elle doit couvrir le raisonnement du modèle
     * en plus du texte rendu : trop juste, la réponse est tronquée avant
     * la dernière proposition, et l'écran n'affiche rien d'exploitable.
     */
    'max_tokens' => (int) env('IA_MAX_TOKENS', 2000),

    'timeout' => (int) env('IA_TIMEOUT', 30),

];
