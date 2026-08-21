<?php

namespace App\Support;

use App\Enums\HealthStatus;

/**
 * Une dérive détectée sur un projet, telle qu'elle doit être remontée
 * au tableau de bord et dans les points d'attention du flash report.
 */
final readonly class Alert
{
    public function __construct(
        public string $code,
        public string $libelle,
        public HealthStatus $severite,
        public ?string $detail = null,
    ) {}

    public static function rouge(string $code, string $libelle, ?string $detail = null): self
    {
        return new self($code, $libelle, HealthStatus::Rouge, $detail);
    }

    public static function orange(string $code, string $libelle, ?string $detail = null): self
    {
        return new self($code, $libelle, HealthStatus::Orange, $detail);
    }

    public function texteComplet(): string
    {
        return $this->detail ? "{$this->libelle} — {$this->detail}" : $this->libelle;
    }
}
