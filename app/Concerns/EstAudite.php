<?php

namespace App\Concerns;

use App\Models\AuditEntry;
use App\Support\Audit\Audit;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Consigne les variations des attributs déclarés sensibles.
 *
 * Le suivi se branche sur l'événement `updated` plutôt que sur les écrans :
 * une valeur modifiée depuis une commande, un import ou un écran que
 * personne n'avait prévu laisse la même trace. C'est la seule façon qu'un
 * journal ait la valeur qu'on lui prête.
 *
 * Les modèles concernés déclarent `attributsAudites()` et `projetAudite()`.
 */
trait EstAudite
{
    /**
     * Attributs dont chaque variation est consignée.
     *
     * @return array<int, string>
     */
    abstract public static function attributsAudites(): array;

    /**
     * Projet auquel rattacher la ligne du journal, pour qu'un historique se
     * lise par projet même quand l'objet modifié est une carte ou un livrable.
     */
    abstract public function projetAudite(): ?int;

    public static function bootEstAudite(): void
    {
        static::updated(function (Model $modele) {
            /** @var self $modele */
            $modele->consignerLesChangements();
        });
    }

    /** @return MorphMany<AuditEntry, $this> */
    public function auditEntries(): MorphMany
    {
        return $this->morphMany(AuditEntry::class, 'auditable')->latest('id');
    }

    private function consignerLesChangements(): void
    {
        $lignes = [];

        foreach (static::attributsAudites() as $attribut) {
            if (! $this->wasChanged($attribut)) {
                continue;
            }

            $avant = $this->lisible($this->getOriginal($attribut));
            $apres = $this->lisible($this->getAttribute($attribut));

            // Un cast peut faire diverger la valeur brute sans rien changer au
            // fond — une date recomposée, un flottant reformaté.
            if ($avant === $apres) {
                continue;
            }

            $lignes[] = [
                'auditable_type' => static::class,
                'auditable_id' => $this->getKey(),
                'project_id' => $this->projetAudite(),
                'auteur_id' => auth()->id(),
                'attribut' => $attribut,
                'ancienne_valeur' => $avant,
                'nouvelle_valeur' => $apres,
                'motif' => Audit::motif(),
                'created_at' => now(),
            ];
        }

        if ($lignes !== []) {
            AuditEntry::insert($lignes);
        }
    }

    /**
     * Rend une valeur consultable des mois plus tard, sans dépendre des casts
     * du modèle ni de l'énumération telle qu'elle sera devenue.
     */
    private function lisible(mixed $valeur): ?string
    {
        return match (true) {
            $valeur === null || $valeur === '' => null,
            $valeur instanceof BackedEnum => (string) $valeur->value,
            $valeur instanceof CarbonInterface => $valeur->format('Y-m-d'),
            is_bool($valeur) => $valeur ? '1' : '0',
            is_float($valeur) => rtrim(rtrim(number_format($valeur, 2, '.', ''), '0'), '.'),
            default => (string) $valeur,
        };
    }
}
