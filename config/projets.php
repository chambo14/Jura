<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Code projet
    |--------------------------------------------------------------------------
    |
    | Chaque projet porte un code court, qui l'identifie au comité, dans les
    | flash reports et dans les exports. Il est attribué automatiquement à la
    | création, sous la forme préfixe-numéro : MLP-0001, MLP-0002, et ainsi
    | de suite.
    |
    | Le préfixe désigne l'entité qui porte le portefeuille. Le changer
    | n'affecte pas les codes déjà attribués : la numérotation reprend à 1
    | sur le nouveau préfixe, chacun ayant sa propre suite.
    |
    */

    'code' => [
        'prefixe' => env('MPM_CODE_PREFIXE', 'MLP'),

        // Nombre de chiffres du compteur. Quatre autorisent 9 999 projets,
        // au-delà desquels le code s'allonge plutôt que de repartir à zéro.
        'chiffres' => (int) env('MPM_CODE_CHIFFRES', 4),
    ],

];
