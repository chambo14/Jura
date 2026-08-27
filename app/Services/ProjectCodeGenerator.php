<?php

namespace App\Services;

use App\Models\Project;

/**
 * Attribue le code d'identification d'un nouveau projet.
 *
 * Le code était saisi à la main, proposé à partir du nom : deux projets d'un
 * même client donnaient des codes voisins et discutables, et l'unicité tenait
 * à la vigilance de qui remplissait le formulaire. Il devient une suite —
 * MLP-0001, MLP-0002 — qui ne se discute pas.
 *
 * La numérotation est propre à chaque préfixe et reprend au plus grand numéro
 * déjà attribué. Les codes hérités d'avant cette règle (BRB-IPS-LOT1 et
 * consorts) ne portent pas le préfixe : ils sont ignorés du calcul et
 * conservés tels quels.
 */
class ProjectCodeGenerator
{
    public function prefixe(): string
    {
        return mb_strtoupper(trim((string) config('projets.code.prefixe', 'MLP')));
    }

    /**
     * Prochain code disponible.
     *
     * Deux créations simultanées peuvent le demander en même temps et recevoir
     * la même réponse : c'est l'index unique de la table qui tranche, et
     * l'appelant redemande un code. Vérifier ici ne supprimerait pas la course,
     * seulement sa fenêtre.
     */
    public function suivant(): string
    {
        $prefixe = $this->prefixe();

        return sprintf(
            '%s-%0'.max(1, (int) config('projets.code.chiffres', 4)).'d',
            $prefixe,
            $this->dernierNumero($prefixe) + 1,
        );
    }

    /**
     * Le plus grand numéro déjà attribué sous ce préfixe.
     *
     * Un code supprimé ne libère pas son numéro : rouvrir un numéro rendrait
     * deux projets différents indiscernables dans les comptes rendus passés.
     */
    private function dernierNumero(string $prefixe): int
    {
        return Project::query()
            ->where('code', 'like', $prefixe.'-%')
            ->pluck('code')
            ->map(function (string $code) use ($prefixe): int {
                $suffixe = mb_substr($code, mb_strlen($prefixe) + 1);

                // « MLP-0007 » compte, « MLP-LOT2 » non : seule une suite de
                // chiffres est un numéro d'ordre.
                return ctype_digit($suffixe) ? (int) $suffixe : 0;
            })
            ->max() ?? 0;
    }
}
