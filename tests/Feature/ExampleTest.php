<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * L'application n'a pas de page vitrine : la racine mène à la connexion.
     */
    public function test_returns_a_successful_response(): void
    {
        $response = $this->get(route('home'));

        $response->assertRedirect(route('login'));
    }
}
