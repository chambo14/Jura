<?php

namespace App\Support\Avancement;

use Carbon\CarbonInterface;

/**
 * Le taux d'avancement d'une équipe à une date passée.
 */
class PointDAvancement
{
    public function __construct(
        public readonly CarbonInterface $date,
        public readonly float $taux,
    ) {}
}
