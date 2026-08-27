<?php

namespace App\Support\Comite;

use App\Enums\ProjectCategory;
use App\Models\FlashReport;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * Une diapositive du comité, quelle que soit sa nature.
 *
 * La séquence mélange quatre natures — l'ouverture, l'intercalaire d'une
 * rubrique, la fiche d'un projet, et le constat d'un projet dont le flash
 * report manque — et chacune ne porte qu'une partie des informations. Les regrouper dans un même objet plutôt que dans des
 * tableaux libres permet à l'écran comme à l'export de savoir ce qu'ils
 * manipulent sans avoir à le deviner.
 */
class Diapositive
{
    /**
     * @param  Collection<int, FlashReport>|null  $projets
     * @param  Collection<int, Project>|null  $rapportsManquants
     */
    private function __construct(
        public readonly string $type,
        public readonly ?ProjectCategory $categorie = null,
        public readonly ?FlashReport $rapport = null,
        public readonly ?Collection $projets = null,
        public readonly ?Project $projet = null,
        public readonly ?Collection $rapportsManquants = null,
    ) {}

    public static function titre(): self
    {
        return new self('titre');
    }

    /**
     * @param  Collection<int, FlashReport>  $projets
     * @param  Collection<int, Project>  $rapportsManquants
     */
    public static function rubrique(ProjectCategory $categorie, Collection $projets, Collection $rapportsManquants): self
    {
        return new self('rubrique', categorie: $categorie, projets: $projets, rapportsManquants: $rapportsManquants);
    }

    public static function projet(ProjectCategory $categorie, FlashReport $rapport): self
    {
        return new self('projet', categorie: $categorie, rapport: $rapport);
    }

    /**
     * Un projet actif dont le flash report de la semaine manque.
     *
     * Il passe quand même au comité : son absence est une information, et la
     * taire reviendrait à le faire disparaître de l'ordre du jour au moment
     * où personne n'a rendu compte de lui.
     */
    public static function sansRapport(ProjectCategory $categorie, Project $projet): self
    {
        return new self('sans_rapport', categorie: $categorie, projet: $projet);
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

    /**
     * @return Collection<int, Project>
     */
    public function rapportsManquants(): Collection
    {
        return $this->rapportsManquants ?? throw new \LogicException(
            "Une diapositive « {$this->type} » ne porte pas de liste de rapports manquants.",
        );
    }

    public function projetSansRapport(): Project
    {
        return $this->projet ?? throw new \LogicException(
            "Une diapositive « {$this->type} » ne porte pas de projet sans rapport.",
        );
    }
}
