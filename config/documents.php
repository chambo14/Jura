<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pièces jointes
    |--------------------------------------------------------------------------
    |
    | Les fichiers déposés sur un projet, un livrable ou une tâche. Le disque
    | par défaut est privé : le téléchargement passe par une route qui vérifie
    | l'accès au projet, jamais par une URL publique.
    |
    */

    'disque' => env('DOCUMENTS_DISK', 'local'),

    // Taille maximale d'un fichier, en kilo-octets. À garder en deçà de
    // `upload_max_filesize` et `post_max_size` de la configuration PHP.
    'taille_max_ko' => (int) env('DOCUMENTS_MAX_KO', 20480),

    // Extensions acceptées : bureautique, images et archives. Tout le reste
    // est refusé côté serveur, quel que soit ce que déclare le navigateur.
    'extensions' => [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp',
        'txt', 'csv', 'md', 'rtf',
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp',
        'zip', 'rar', '7z',
        'msg', 'eml',
    ],

];
