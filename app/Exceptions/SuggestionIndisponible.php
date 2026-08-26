<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * L'assistant de rédaction n'a pas pu répondre : clé absente, service
 * injoignable, réponse inexploitable. Le message est destiné à l'écran —
 * il dit à l'utilisateur quoi faire, pas ce qui s'est cassé.
 */
class SuggestionIndisponible extends RuntimeException
{
    public static function nonConfigure(): self
    {
        return new self("L'assistant de rédaction n'est pas configuré sur cette installation.");
    }

    public static function injoignable(): self
    {
        return new self("L'assistant de rédaction n'a pas répondu. Réessayez dans un instant.");
    }

    public static function reponseVide(): self
    {
        return new self("L'assistant n'a rien proposé pour ce contexte. Complétez la fiche projet et réessayez.");
    }
}
