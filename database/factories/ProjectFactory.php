<?php

namespace Database\Factories;

use App\Enums\ProjectCategory;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Models\Client;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $debut = now()->subMonths(6)->startOfDay();

        return [
            'code' => Str::upper(fake()->unique()->bothify('PRJ-####')),
            'nom' => 'Projet '.fake()->unique()->word(),
            'description' => fake()->sentence(),
            'client_id' => Client::factory(),
            'type_projet' => ProjectType::Deploiement,
            'categorie' => ProjectCategory::Autres,
            'ordre_comite' => 0,
            'pays_domiciliation' => "Côte d'Ivoire",
            'statut' => ProjectStatus::EnCours,
            'date_debut' => $debut,
            'date_fin_initiale' => $debut->addMonths(12),
            'date_fin_revisee' => null,
            'avancement_pct' => 50,
            'taux_realisation_pct' => 50,
        ];
    }

    public function type(ProjectType $type): static
    {
        return $this->state(fn () => ['type_projet' => $type]);
    }

    /**
     * Projet dont la date de fin a glissé et dont l'avancement décroche.
     */
    public function enDerive(): static
    {
        return $this->state(fn (array $attributs) => [
            'date_fin_initiale' => now()->subMonths(3),
            'date_fin_revisee' => now()->addMonths(3),
            'avancement_pct' => 20,
            'taux_realisation_pct' => 20,
        ]);
    }
}
