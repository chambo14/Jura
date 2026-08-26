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

    'modele' => env('IA_MODELE', 'claude-sonnet-4-5'),

    'version' => env('IA_VERSION', '2023-06-01'),

    // Longueur de la réponse et patience du serveur : une suggestion se
    // rédige en quelques secondes, sinon elle ne sert plus à rien.
    'max_tokens' => (int) env('IA_MAX_TOKENS', 700),

    'timeout' => (int) env('IA_TIMEOUT', 30),

];
