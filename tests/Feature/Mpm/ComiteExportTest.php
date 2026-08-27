<?php

namespace Tests\Feature\Mpm;

use App\Enums\FlashReportStatus;
use App\Enums\ProjectCategory;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\Project;
use App\Models\User;
use App\Services\ComiteBuilder;
use App\Services\ComiteExporter;
use App\Services\FlashReportBuilder;
use Database\Seeders\MpmReferentialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class ComiteExportTest extends TestCase
{
    use RefreshDatabase;

    /** Lundi de la semaine de référence des tests. */
    private const LUNDI = '2026-08-10';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MpmReferentialSeeder::class);
    }

    private function projetAvecRapport(string $nom = 'Projet pilote', ?ProjectCategory $categorie = null): Project
    {
        $projet = Project::factory()->create([
            'nom' => $nom,
            'categorie' => $categorie ?? ProjectCategory::Monetique,
            'date_debut' => Date::parse('2026-01-05'),
            'date_fin_initiale' => Date::parse('2026-12-31'),
        ]);

        app(FlashReportBuilder::class)->preparer($projet, Date::parse(self::LUNDI));

        return $projet;
    }

    public function test_lexport_sert_un_fichier_powerpoint_telechargeable(): void
    {
        $this->projetAvecRapport();

        $reponse = $this->actingAs(User::factory()->role(UserRole::Direction)->create())
            ->get(route('comite.export', ['semaine' => self::LUNDI]));

        $reponse->assertOk();
        $reponse->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        );
        $reponse->assertDownload('comite-projets-2026-08-10.pptx');
    }

    public function test_le_fichier_produit_est_une_archive_ooxml_lisible(): void
    {
        $projet = $this->projetAvecRapport('Interopérabilité « BRB » & <IPS>');

        $chemin = $this->fichierDe(User::factory()->role(UserRole::Direction)->create());

        $archive = new ZipArchive;
        $this->assertTrue($archive->open($chemin) === true, "L'export n'est pas une archive ZIP valide.");

        // Les parties qu'un lecteur OOXML exige pour ouvrir la présentation.
        foreach ([
            '[Content_Types].xml',
            '_rels/.rels',
            'ppt/presentation.xml',
            'ppt/_rels/presentation.xml.rels',
            'ppt/theme/theme1.xml',
            'ppt/slideMasters/slideMaster1.xml',
            'ppt/slideLayouts/slideLayout1.xml',
            'ppt/slides/slide1.xml',
        ] as $partie) {
            $this->assertNotFalse($archive->locateName($partie), "Partie absente de l'archive : {$partie}");
        }

        // Titre + intercalaire de rubrique + fiche projet.
        $this->assertSame(3, $archive->numFiles - $this->nonDiapositives($archive));

        foreach (range(1, 3) as $rang) {
            $xml = $archive->getFromName("ppt/slides/slide{$rang}.xml");
            $this->assertIsString($xml);
            $this->assertNotFalse(simplexml_load_string($xml), "La diapositive {$rang} n'est pas du XML valide.");
        }

        // Le nom du projet contient des caractères que XML réserve : il doit
        // ressortir échappé, pas brut, sans quoi le fichier serait illisible.
        $fiche = (string) $archive->getFromName('ppt/slides/slide3.xml');
        $this->assertStringContainsString('Interopérabilité « BRB » &amp; &lt;IPS&gt;', $fiche);

        $archive->close();
        @unlink($chemin);
    }

    public function test_lexport_ignore_les_projets_hors_du_perimetre_de_lutilisateur(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create();

        $sien = $this->projetAvecRapport('Projet du chef');
        $sien->update(['chef_projet_id' => $chef->id]);

        $this->projetAvecRapport('Projet dun autre');

        $rapports = app(ComiteBuilder::class)->rapports($chef, Date::parse(self::LUNDI));

        $this->assertSame(['Projet du chef'], $rapports->pluck('project.nom')->all());
    }

    public function test_sans_brouillon_demande_seuls_les_rapports_publies_sortent(): void
    {
        $projet = $this->projetAvecRapport();
        $direction = User::factory()->role(UserRole::Direction)->create();

        $builder = app(ComiteBuilder::class);

        $this->assertCount(0, $builder->rapports($direction, Date::parse(self::LUNDI), false));

        $projet->flashReports()->update(['statut' => FlashReportStatus::Publie->value]);

        $this->assertCount(1, $builder->rapports($direction, Date::parse(self::LUNDI), false));
    }

    /**
     * Un projet actif dont le rapport manque ne disparaît plus : il est présenté
     * comme donnée manquante, sans qu'aucun chiffre soit reconstitué.
     */
    public function test_un_projet_actif_sans_rapport_reste_a_lordre_du_jour(): void
    {
        $this->projetAvecRapport('Projet qui a rendu');
        $muet = Project::factory()->create(['nom' => 'Projet resté muet', 'categorie' => ProjectCategory::Monetique]);

        $builder = app(ComiteBuilder::class);
        $direction = User::factory()->role(UserRole::Direction)->create();

        $sequence = $builder->sequence($direction, Date::parse(self::LUNDI));

        $manquants = $sequence->where('type', 'sans_rapport');
        $this->assertCount(1, $manquants);
        $this->assertTrue($manquants->first()->projetSansRapport()->is($muet));

        // La rubrique le porte aussi, pour qu'il figure au sommaire.
        $rubrique = $sequence->firstWhere('type', 'rubrique');
        $this->assertSame(['Projet resté muet'], $rubrique->rapportsManquants()->pluck('nom')->all());
    }

    public function test_un_projet_clos_ne_reclame_pas_de_rapport(): void
    {
        $this->projetAvecRapport();
        Project::factory()->create(['nom' => 'Projet clôturé', 'statut' => ProjectStatus::Cloture]);

        $sequence = app(ComiteBuilder::class)->sequence(
            User::factory()->role(UserRole::Direction)->create(),
            Date::parse(self::LUNDI),
        );

        $this->assertCount(0, $sequence->where('type', 'sans_rapport'));
    }

    public function test_lexport_dune_semaine_sans_rapport_presente_quand_meme_les_projets_actifs(): void
    {
        Project::factory()->create(['nom' => 'Projet resté muet']);

        $this->actingAs(User::factory()->role(UserRole::Direction)->create())
            ->get(route('comite.export', ['semaine' => '2026-09-14']))
            ->assertOk()
            ->assertDownload('comite-projets-2026-09-14.pptx');
    }

    public function test_sans_aucun_projet_actif_il_ny_a_rien_a_exporter(): void
    {
        Project::query()->update(['statut' => ProjectStatus::Cloture->value]);

        $this->actingAs(User::factory()->role(UserRole::Direction)->create())
            ->get(route('comite.export', ['semaine' => '2026-09-14']))
            ->assertNotFound();
    }

    public function test_lecran_du_comite_propose_le_telechargement(): void
    {
        $this->projetAvecRapport();

        $this->actingAs(User::factory()->role(UserRole::Direction)->create())
            ->get(route('comite', ['semaine' => self::LUNDI]))
            ->assertOk()
            ->assertSee(e(route('comite.export', ['semaine' => self::LUNDI, 'brouillons' => 1])), escape: false);
    }

    /**
     * Le pied de fiche affichait l'auteur du rapport sous le libellé « Chef de
     * projet », attribuant le projet à qui l'avait saisi — et contredisant
     * l'en-tête de la même diapositive.
     */
    public function test_la_fiche_comite_nomme_le_chef_de_projet_pas_lauteur_du_rapport(): void
    {
        $chef = User::factory()->role(UserRole::ChefProjet)->create(['name' => 'Siaka KONÉ']);
        $redacteur = User::factory()->role(UserRole::Membre)->create(['name' => 'Awa TRAORÉ']);

        $projet = $this->projetAvecRapport();
        $projet->update(['chef_projet_id' => $chef->id]);
        $projet->flashReports()->update(['auteur_id' => $redacteur->id]);

        // La séquence est : titre, intercalaire de rubrique, puis la fiche.
        $ecran = Livewire::actingAs(User::factory()->role(UserRole::Direction)->create())
            ->test('pages::comite', ['semaine' => self::LUNDI])
            ->set('semaine', self::LUNDI)
            ->set('index', 2);

        $ecran->assertSee('Chef de projet : Siaka KONÉ', escape: false);
        $ecran->assertSee('Rapport rédigé par Awa TRAORÉ', escape: false);
        $ecran->assertDontSee('Chef de projet : Awa TRAORÉ', escape: false);
    }

    public function test_lexport_est_ferme_aux_visiteurs(): void
    {
        $this->get(route('comite.export'))->assertRedirect(route('login'));
    }

    private function fichierDe(User $utilisateur): string
    {
        $builder = app(ComiteBuilder::class);
        $lundi = FlashReportBuilder::lundiDe(Date::parse(self::LUNDI));

        return app(ComiteExporter::class)->exporter(
            $builder->diapositives($builder->rapports($utilisateur, $lundi)),
            $lundi,
        );
    }

    /**
     * Nombre de parties de l'archive qui ne sont pas des diapositives.
     */
    private function nonDiapositives(ZipArchive $archive): int
    {
        $total = 0;

        for ($rang = 0; $rang < $archive->numFiles; $rang++) {
            $nom = (string) $archive->getNameIndex($rang);

            if (! str_starts_with($nom, 'ppt/slides/slide') || ! str_ends_with($nom, '.xml')) {
                $total++;
            }
        }

        return $total;
    }
}
