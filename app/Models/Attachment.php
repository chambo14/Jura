<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $project_id
 * @property string $attachable_type
 * @property int $attachable_id
 * @property int|null $depose_par_id
 * @property string $nom
 * @property string $chemin
 * @property string $disque
 * @property string|null $mime
 * @property int $taille
 * @property string|null $description
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Project $project
 * @property-read User|null $deposePar
 * @property-read Model $attachable
 */
#[Fillable([
    'project_id', 'attachable_type', 'attachable_id', 'depose_par_id',
    'nom', 'chemin', 'disque', 'mime', 'taille', 'description',
])]
class Attachment extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['taille' => 'integer'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function deposePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'depose_par_id');
    }

    /** @return MorphTo<Model, $this> */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Ce à quoi le fichier est rattaché, en clair : « Livrable — Cahier des
     * charges », « Tâche — Recette », ou le projet lui-même.
     */
    public function rattachement(): string
    {
        $cible = $this->attachable;

        return match (true) {
            $cible instanceof Deliverable => 'Livrable — '.$cible->nom,
            $cible instanceof Task => 'Tâche — '.$cible->libelle,
            default => 'Projet',
        };
    }

    public function tailleLisible(): string
    {
        $octets = max($this->taille, 0);

        if ($octets < 1024) {
            return $octets.' o';
        }

        $unites = ['Ko', 'Mo', 'Go'];
        $valeur = $octets / 1024;
        $rang = 0;

        while ($valeur >= 1024 && $rang < count($unites) - 1) {
            $valeur /= 1024;
            $rang++;
        }

        return number_format($valeur, $valeur >= 10 ? 0 : 1, ',', ' ').' '.$unites[$rang];
    }

    public function estImage(): bool
    {
        return Str::startsWith((string) $this->mime, 'image/');
    }

    /**
     * Icône du type de fichier, pour que la liste se lise d'un coup d'œil.
     */
    public function icone(): string
    {
        $extension = Str::lower(pathinfo($this->nom, PATHINFO_EXTENSION));

        return match (true) {
            $this->estImage() => 'photo',
            $extension === 'pdf' => 'document-text',
            in_array($extension, ['xls', 'xlsx', 'csv'], true) => 'table-cells',
            in_array($extension, ['ppt', 'pptx'], true) => 'presentation-chart-bar',
            in_array($extension, ['zip', 'rar', '7z'], true) => 'archive-box',
            default => 'paper-clip',
        };
    }

    /**
     * Le fichier est-il encore présent sur le disque ? Un dépôt manuel sur
     * l'hébergement peut laisser la ligne sans son fichier.
     */
    public function existe(): bool
    {
        return Storage::disk($this->disque)->exists($this->chemin);
    }
}
