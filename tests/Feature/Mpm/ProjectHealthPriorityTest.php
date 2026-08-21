<?php

namespace Tests\Feature\Mpm;

use App\Enums\HealthStatus;
use App\Support\Alert;
use App\Support\ProjectHealth;
use PHPUnit\Framework\TestCase;

class ProjectHealthPriorityTest extends TestCase
{
    /**
     * @param  array<int, Alert>  $alertes
     */
    private function sante(array $alertes, HealthStatus $statut = HealthStatus::Rouge): ProjectHealth
    {
        return new ProjectHealth($statut, collect($alertes), 0, null, null);
    }

    public function test_le_motif_principal_privilegie_une_echeance_depassee_au_glissement(): void
    {
        $sante = $this->sante([
            Alert::rouge('glissement', 'Glissement de 545 jours sur la date de fin'),
            Alert::rouge('echeance_depassee', 'Échéance dépassée de 4 jours'),
        ]);

        $this->assertSame('echeance_depassee', $sante->alertePrincipale()?->code);
    }

    public function test_le_motif_principal_privilegie_les_jalons_aux_taches(): void
    {
        $sante = $this->sante([
            Alert::rouge('taches', '6 tâches en retard'),
            Alert::rouge('jalons', '2 jalons critiques en retard'),
        ]);

        $this->assertSame('jalons', $sante->alertePrincipale()?->code);
    }

    public function test_une_alerte_rouge_prime_sur_une_orange_plus_actionnable(): void
    {
        $sante = $this->sante([
            Alert::orange('echeance_depassee', 'Échéance dépassée'),
            Alert::rouge('glissement', 'Glissement important'),
        ]);

        $this->assertSame('glissement', $sante->alertePrincipale()?->code);
    }

    public function test_sans_alerte_rouge_le_motif_vient_des_alertes_orange(): void
    {
        $sante = $this->sante([
            Alert::orange('glissement', 'Glissement de 20 jours'),
            Alert::orange('taches', '2 tâches en retard'),
        ], HealthStatus::Orange);

        $this->assertSame('taches', $sante->alertePrincipale()?->code);
    }

    public function test_un_projet_sain_na_pas_de_motif(): void
    {
        $this->assertNull($this->sante([], HealthStatus::Vert)->alertePrincipale());
    }

    public function test_la_priorite_classe_le_rouge_avant_lorange(): void
    {
        $rouge = $this->sante([Alert::rouge('taches', '…')]);
        $orange = $this->sante([Alert::orange('taches', '…')], HealthStatus::Orange);

        $this->assertGreaterThan($orange->priorite(), $rouge->priorite());
    }

    public function test_a_gravite_egale_le_nombre_dalertes_rouges_departage(): void
    {
        $deux = $this->sante([Alert::rouge('taches', '…'), Alert::rouge('jalons', '…')]);
        $une = $this->sante([Alert::rouge('taches', '…')]);

        $this->assertGreaterThan($une->priorite(), $deux->priorite());
    }
}
