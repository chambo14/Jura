<?php

namespace App\Services;

use App\Enums\FlashItemType;
use App\Enums\MemberRole;
use App\Models\FlashReport;
use App\Models\ProjectMember;
use App\Support\Comite\Diapositive;
use App\Support\Pptx\Deck;
use App\Support\Pptx\Forme;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Transpose la séquence du mode comité en fichier PowerPoint.
 *
 * L'écran et le fichier partent de la même séquence — diapositive de titre,
 * intercalaire par rubrique, une diapositive par projet — pour qu'un export
 * ne raconte jamais autre chose que ce qui a été projeté en séance.
 */
class ComiteExporter
{
    /** Couleurs reprises des slides existantes. */
    private const NAVY = '1F4E79';

    private const BORDEAUX = '960000';

    private const ENCRE = '27272A';

    private const GRIS = '71717A';

    /** Marges et gouttières de la diapositive, en EMU. */
    private const MARGE = 400000;

    private const GOUTTIERE = 300000;

    /**
     * @param  Collection<int, Diapositive>  $diapositives
     * @return string chemin du fichier temporaire produit
     */
    public function exporter(Collection $diapositives, CarbonInterface $lundi): string
    {
        $deck = new Deck('Comité Projets — semaine du '.$lundi->format('d/m/Y'));

        $utile = $deck->largeur() - 2 * self::MARGE;

        foreach ($diapositives as $diapositive) {
            match ($diapositive->type) {
                'titre' => $this->titre($deck, $diapositives, $lundi, $utile),
                'rubrique' => $this->rubrique($deck, $diapositive, $utile),
                'projet' => $this->projet($deck, $diapositive->rapport(), $utile),
                default => null,
            };
        }

        return $deck->ecrire();
    }

    public function nomDuFichier(CarbonInterface $lundi): string
    {
        return 'comite-projets-'.$lundi->format('Y-m-d').'.pptx';
    }

    /**
     * @param  Collection<int, Diapositive>  $diapositives
     */
    private function titre(Deck $deck, Collection $diapositives, CarbonInterface $lundi, int $utile): void
    {
        $projets = $diapositives->where('type', 'projet')->count();
        $rubriques = $diapositives->where('type', 'rubrique')->count();

        $bloc = Forme::bloc(self::MARGE, 2200000, $utile, 2000000, centre: true)
            ->ligne('COMITÉ PROJETS', 4000, self::NAVY, gras: true)
            ->ligne('', 1200)
            ->ligne(
                'Semaine du '.$lundi->translatedFormat('j F').' au '.$lundi->addDays(4)->translatedFormat('j F Y'),
                2000,
                self::ENCRE,
            )
            ->ligne('', 1000)
            ->ligne(
                $projets.' '.($projets > 1 ? 'projets' : 'projet').' · '.$rubriques.' '.($rubriques > 1 ? 'rubriques' : 'rubrique'),
                1400,
                self::GRIS,
            );

        $deck->ajouter([$bloc]);
    }

    private function rubrique(Deck $deck, Diapositive $diapositive, int $utile): void
    {
        $categorie = $diapositive->categorie();
        $projets = $diapositive->projets();

        $entete = Forme::bloc(self::MARGE, 1600000, $utile, 700000, self::NAVY)
            ->ligne(mb_strtoupper($categorie->label()), 2400, 'FFFFFF', gras: true);

        $liste = Forme::bloc(self::MARGE, 2500000, $utile, 3400000)
            ->puces(
                $projets->map(fn (FlashReport $rapport) => $rapport->project->code.' — '.$rapport->project->nom),
                1600,
                self::ENCRE,
            );

        $deck->ajouter([$entete, $liste]);
    }

    private function projet(Deck $deck, FlashReport $rapport, int $utile): void
    {
        $projet = $rapport->project;

        $formes = [];

        // ── Bandeau d'identification ───────────────────────────────────────
        $formes[] = Forme::bloc(self::MARGE, 300000, $utile, 800000, self::NAVY)
            ->ligne($projet->nom, 2000, 'FFFFFF', gras: true)
            ->ligne(
                $projet->code.' · '.$projet->client->displayName().' · '.$projet->type_projet->label(),
                1100,
                'DBEAFE',
            );

        // ── Pilotage et planning, côte à côte ──────────────────────────────
        $colonne = (int) (($utile - self::GOUTTIERE) / 2);

        $formes[] = $this->section(self::MARGE, 1250000, $colonne, 'PILOTAGE', $this->pilotage($rapport));
        $formes[] = $this->section(
            self::MARGE + $colonne + self::GOUTTIERE,
            1250000,
            $colonne,
            'PLANNING & AVANCEMENT',
            $this->planning($rapport),
        );

        // ── Les trois rubriques d'activités ────────────────────────────────
        $tiers = (int) (($utile - 2 * self::GOUTTIERE) / 3);
        $rubriques = [FlashItemType::Realisee, FlashItemType::Attention, FlashItemType::ARealiser];

        foreach ($rubriques as $rang => $type) {
            $x = self::MARGE + $rang * ($tiers + self::GOUTTIERE);

            $formes[] = $this->section(
                $x,
                3350000,
                $tiers,
                mb_strtoupper($type->shortLabel()),
                $this->lignesDe($rapport, $type),
                hauteur: 3100000,
                couleurEntete: $type === FlashItemType::Attention ? self::BORDEAUX : self::NAVY,
            );
        }

        $deck->ajouter($formes);
    }

    /**
     * Un titre en bandeau et son contenu, la mise en forme récurrente de ces
     * diapositives.
     *
     * @param  array<int, string>  $lignes
     */
    private function section(
        int $x,
        int $y,
        int $largeur,
        string $titre,
        array $lignes,
        int $hauteur = 1900000,
        string $couleurEntete = self::NAVY,
    ): Forme {
        $bloc = Forme::bloc($x, $y, $largeur, $hauteur)
            ->ligne($titre, 1100, $couleurEntete, gras: true)
            ->ligne('', 600);

        if ($lignes === []) {
            return $bloc->ligne('Aucun élément.', 1000, self::GRIS);
        }

        return $bloc->puces($lignes, 1000, self::ENCRE);
    }

    /**
     * @return array<int, string>
     */
    private function pilotage(FlashReport $rapport): array
    {
        $projet = $rapport->project;

        $testeurs = $projet->members
            ->where('role', MemberRole::Testeur)
            ->map(fn (ProjectMember $membre) => $membre->user->name)
            ->filter()
            ->implode(', ');

        return $this->sansVide([
            'Chef de projet : '.($projet->chefProjet->name ?? '—'),
            'Back-up : '.($projet->backUp->name ?? '—'),
            'Sponsor : '.($projet->sponsor->name ?? '—'),
            'Référent technique : '.($projet->referentTechnique->name ?? '—'),
            $testeurs !== '' ? 'Testeurs : '.$testeurs : null,
            $projet->pays_domiciliation ? 'Domiciliation : '.$projet->pays_domiciliation : null,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function planning(FlashReport $rapport): array
    {
        return $this->sansVide([
            'Date de début : '.($rapport->date_debut?->format('d/m/Y') ?? '—'),
            'Fin initiale : '.($rapport->date_fin_initiale?->format('d/m/Y') ?? '—'),
            'Fin révisée : '.($rapport->date_fin_revisee?->format('d/m/Y') ?? '—'),
            'Avancement : '.number_format($rapport->avancement_pct, 2, ',', ' ').' %',
            'Taux de réalisation : '.number_format($rapport->taux_realisation_pct, 2, ',', ' ').' %',
            $rapport->workflowStep ? 'Étape courante : '.$rapport->workflowStep->libelle : null,
            $rapport->phase ? 'Phase MPM : '.$rapport->phase->nom : null,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function lignesDe(FlashReport $rapport, FlashItemType $type): array
    {
        return $rapport->itemsOfType($type)
            ->map(function ($item) {
                $texte = $item->datePrefix() ? $item->datePrefix().' : '.$item->libelle : $item->libelle;

                return $item->statut_libelle ? $texte.' ('.$item->statut_libelle.')' : $texte;
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string|null>  $lignes
     * @return array<int, string>
     */
    private function sansVide(array $lignes): array
    {
        return array_values(array_filter($lignes, fn (?string $ligne) => filled($ligne)));
    }
}
