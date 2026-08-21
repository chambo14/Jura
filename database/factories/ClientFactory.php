<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $nom = fake()->unique()->company();

        return [
            'nom' => $nom,
            'sigle' => Str::upper(Str::substr(Str::slug($nom, ''), 0, 4)),
            'pays' => fake()->country(),
            'secteur' => 'Banque',
        ];
    }
}
