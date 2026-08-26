<?php

namespace App\Support\Pptx;

use RuntimeException;
use ZipArchive;

/**
 * Fabrique un fichier PowerPoint sans dépendance externe.
 *
 * Un .pptx est une archive ZIP d'documents XML normalisés (OOXML). Pour un
 * jeu de diapositives entièrement textuel — un titre, des intercalaires, des
 * listes — l'ossature tient en une douzaine de fichiers, tous écrits ici.
 *
 * Le choix de ne rien ajouter à `composer.json` n'est pas de la coquetterie :
 * la moindre dépendance oblige à renvoyer `vendor/` sur l'hébergement, ce que
 * l'antivirus du serveur a déjà refusé, et la bibliothèque de référence
 * entraîne huit paquets dont un tableur complet pour afficher du texte.
 *
 * Les dimensions sont en EMU (English Metric Units), l'unité d'OOXML :
 * 914 400 EMU par pouce, soit 12 700 par point typographique.
 */
class Deck
{
    /** Diapositive 16/9 : 13,333 × 7,5 pouces. */
    private const LARGEUR = 12192000;

    private const HAUTEUR = 6858000;

    /** @var array<int, string> XML de chaque diapositive, dans l'ordre. */
    private array $diapositives = [];

    public function __construct(
        private readonly string $titre = 'Présentation',
        private readonly string $auteur = 'Suivi Projets MPM',
    ) {}

    public function largeur(): int
    {
        return self::LARGEUR;
    }

    public function hauteur(): int
    {
        return self::HAUTEUR;
    }

    /**
     * Ajoute une diapositive composée de formes déjà rendues.
     *
     * @param  array<int, Forme>  $formes
     */
    public function ajouter(array $formes, ?string $fondHexa = null): void
    {
        $rang = count($this->diapositives) + 1;

        $corps = '';
        foreach ($formes as $index => $forme) {
            $corps .= $forme->xml($index + 2);
        }

        $fond = $fondHexa === null ? '' : <<<XML
            <p:bg><p:bgPr><a:solidFill><a:srgbClr val="{$fondHexa}"/></a:solidFill><a:effectLst/></p:bgPr></p:bg>
            XML;

        $this->diapositives[$rang] = <<<XML
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">
            <p:cSld>{$fond}<p:spTree>
            <p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>
            <p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>
            {$corps}
            </p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>
            XML;
    }

    public function nombreDeDiapositives(): int
    {
        return count($this->diapositives);
    }

    /**
     * Écrit l'archive et rend son chemin. L'appelant est responsable de la
     * suppression du fichier temporaire.
     */
    public function ecrire(): string
    {
        if ($this->diapositives === []) {
            throw new RuntimeException('Un fichier PowerPoint sans diapositive ne peut pas être produit.');
        }

        $chemin = tempnam(sys_get_temp_dir(), 'comite_').'.pptx';
        $archive = new ZipArchive;

        if ($archive->open($chemin, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("L'archive PowerPoint n'a pas pu être ouverte en écriture.");
        }

        foreach ($this->fichiers() as $nom => $contenu) {
            $archive->addFromString($nom, $this->compacter($contenu));
        }

        $archive->close();

        return $chemin;
    }

    /**
     * Les XML sont écrits indentés pour rester lisibles à la relecture ; les
     * consommateurs, eux, n'ont que faire des retours à la ligne.
     */
    private function compacter(string $xml): string
    {
        return preg_replace('/\n\s*/', '', trim($xml)) ?? $xml;
    }

    /**
     * @return array<string, string>
     */
    private function fichiers(): array
    {
        $fichiers = [
            '[Content_Types].xml' => $this->contentTypes(),
            '_rels/.rels' => $this->relsRacine(),
            'docProps/core.xml' => $this->core(),
            'docProps/app.xml' => $this->app(),
            'ppt/presentation.xml' => $this->presentation(),
            'ppt/_rels/presentation.xml.rels' => $this->relsPresentation(),
            'ppt/presProps.xml' => $this->presProps(),
            'ppt/theme/theme1.xml' => Theme::xml(),
            'ppt/slideMasters/slideMaster1.xml' => $this->slideMaster(),
            'ppt/slideMasters/_rels/slideMaster1.xml.rels' => $this->relsSlideMaster(),
            'ppt/slideLayouts/slideLayout1.xml' => $this->slideLayout(),
            'ppt/slideLayouts/_rels/slideLayout1.xml.rels' => $this->relsSlideLayout(),
        ];

        foreach ($this->diapositives as $rang => $xml) {
            $fichiers["ppt/slides/slide{$rang}.xml"] = $xml;
            $fichiers["ppt/slides/_rels/slide{$rang}.xml.rels"] = $this->relsSlide();
        }

        return $fichiers;
    }

    private function contentTypes(): string
    {
        $slides = '';
        foreach (array_keys($this->diapositives) as $rang) {
            $slides .= '<Override PartName="/ppt/slides/slide'.$rang.'.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>';
        }

        return <<<XML
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
            <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
            <Default Extension="xml" ContentType="application/xml"/>
            <Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>
            <Override PartName="/ppt/presProps.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presProps+xml"/>
            <Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>
            <Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>
            <Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>
            {$slides}
            <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
            <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
            </Types>
            XML;
    }

    private function relsRacine(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>
            <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
            <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
            </Relationships>
            XML;
    }

    private function core(): string
    {
        $titre = Forme::echapper($this->titre);
        $auteur = Forme::echapper($this->auteur);
        $date = gmdate('Y-m-d\TH:i:s\Z');

        return <<<XML
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
            <dc:title>{$titre}</dc:title>
            <dc:creator>{$auteur}</dc:creator>
            <cp:lastModifiedBy>{$auteur}</cp:lastModifiedBy>
            <dcterms:created xsi:type="dcterms:W3CDTF">{$date}</dcterms:created>
            <dcterms:modified xsi:type="dcterms:W3CDTF">{$date}</dcterms:modified>
            </cp:coreProperties>
            XML;
    }

    private function app(): string
    {
        $nombre = $this->nombreDeDiapositives();

        return <<<XML
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
            <Application>Suivi Projets MPM</Application>
            <Slides>{$nombre}</Slides>
            <Paragraphs>0</Paragraphs>
            </Properties>
            XML;
    }

    private function presentation(): string
    {
        $ids = '';
        foreach (array_keys($this->diapositives) as $rang) {
            // Les identifiants de diapositive doivent dépasser 255.
            $id = 255 + $rang;
            $ids .= '<p:sldId id="'.$id.'" r:id="rId'.($rang + 1).'"/>';
        }

        $largeur = self::LARGEUR;
        $hauteur = self::HAUTEUR;

        return <<<XML
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <p:presentation xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" saveSubsetFonts="1">
            <p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>
            <p:sldIdLst>{$ids}</p:sldIdLst>
            <p:sldSz cx="{$largeur}" cy="{$hauteur}"/>
            <p:notesSz cx="{$hauteur}" cy="{$largeur}"/>
            </p:presentation>
            XML;
    }

    private function relsPresentation(): string
    {
        $relations = '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>';

        foreach (array_keys($this->diapositives) as $rang) {
            $relations .= '<Relationship Id="rId'.($rang + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide'.$rang.'.xml"/>';
        }

        $presProps = $this->nombreDeDiapositives() + 2;
        $theme = $presProps + 1;

        return <<<XML
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            {$relations}
            <Relationship Id="rId{$presProps}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/presProps" Target="presProps.xml"/>
            <Relationship Id="rId{$theme}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="theme/theme1.xml"/>
            </Relationships>
            XML;
    }

    private function presProps(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <p:presentationPr xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>
            XML;
    }

    private function slideMaster(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <p:sldMaster xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">
            <p:cSld><p:bg><p:bgPr><a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill><a:effectLst/></p:bgPr></p:bg>
            <p:spTree>
            <p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>
            <p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>
            </p:spTree></p:cSld>
            <p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>
            <p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst>
            <p:txStyles><p:titleStyle/><p:bodyStyle/><p:otherStyle/></p:txStyles>
            </p:sldMaster>
            XML;
    }

    private function relsSlideMaster(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>
            <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/>
            </Relationships>
            XML;
    }

    private function slideLayout(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <p:sldLayout xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" type="blank" preserve="1">
            <p:cSld name="Vierge"><p:spTree>
            <p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>
            <p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>
            </p:spTree></p:cSld>
            <p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr>
            </p:sldLayout>
            XML;
    }

    private function relsSlideLayout(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/>
            </Relationships>
            XML;
    }

    private function relsSlide(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
            <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>
            </Relationships>
            XML;
    }
}
