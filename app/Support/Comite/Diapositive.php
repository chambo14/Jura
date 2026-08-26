<?php

namespace App\Support\Comite;

use App\Enums\ProjectCategory;
use App\Models\FlashReport;
use Illuminate\Support\Collection;

/**
 * Une diapositive du comité, quelle que soit sa nature.
 *
 * La séquence mélange trois natures — l'ouverture, l'intercalaire d'une
 * rubrique, la fiche d'un projet — et chacune ne porte qu'une partie des
 * informations. Les regrouper dans un même objet plutôt que dans des
 * tableaux libres permet à l'écran comme à l'export de savoir ce qu'ils
 * manipulent sans avoir à le deviner.
 */
class Diapositive
{
    /**
     * @param  Collection<int, FlashReport>|null  $projets
     */
    private function __construct(
        public readonly string $type,
        public readonly ?ProjectCategory $categorie = null,
        public readonly ?FlashReport $rapport = null,
        public readonly ?Collection $projets = null,
    ) {}

    public static function titre(): self
    {
        return new self('titre');
    }

    /**
     * @param  Collection<int, FlashReport>  $projets
     */
    public static function rubrique(ProjectCategory $categorie, Collection $projets): self
    {
        return new self('rubrique', categorie: $categorie, projets: $projets);
    }

    public static function projet(ProjectCategory $categorie, FlashReport $rapport): self
    {
        return new self('projet', categorie: $categorie, rapport: $rapport);
    }

    /**
     * La rubrique de la diapositive, qui n'a de sens que hors de l'ouverture.
     */
    public function categorie(): ProjectCategory
    {
        return $this->categorie ?? throw new \LogicException(
            "Une diapositive « {$this->type} » ne porte pas de rubrique.",
        );
    }

    public function rapport(): FlashReport
    {
        return $this->rapport ?? throw new \LogicException(
            "Une diapositive « {$this->type} » ne porte pas de flash report.",
        );
    }

    /**
     * @return Collection<int, FlashReport>
     */
    public function projets(): Collection
    {
        return $this->projets ?? throw new \LogicException(
            "Une diapositive « {$this->type} » ne porte pas de liste de projets.",
        );
    }
}
