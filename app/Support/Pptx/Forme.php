<?php

namespace App\Support\Pptx;

/**
 * Un bloc de texte posé à une position donnée d'une diapositive.
 *
 * OOXML ne connaît pas la notion de « bloc qui s'adapte » : chaque forme est
 * placée en absolu, en EMU. Les tailles de police sont en centièmes de point
 * (`sz="2400"` vaut 24 pt).
 */
class Forme
{
    /** @var array<int, array{texte: string, puce: bool, taille: int, couleur: string, gras: bool}> */
    private array $paragraphes = [];

    private function __construct(
        private readonly int $x,
        private readonly int $y,
        private readonly int $largeur,
        private readonly int $hauteur,
        private readonly ?string $fondHexa = null,
        private readonly bool $centre = false,
    ) {}

    public static function bloc(int $x, int $y, int $largeur, int $hauteur, ?string $fondHexa = null, bool $centre = false): self
    {
        return new self($x, $y, $largeur, $hauteur, $fondHexa, $centre);
    }

    /**
     * Ajoute une ligne. Les lignes vides sont conservées : elles font
     * respirer une diapositive dense.
     */
    public function ligne(string $texte, int $taille = 1400, string $couleur = '3F3F46', bool $gras = false, bool $puce = false): self
    {
        $this->paragraphes[] = [
            'texte' => $texte,
            'puce' => $puce,
            'taille' => $taille,
            'couleur' => $couleur,
            'gras' => $gras,
        ];

        return $this;
    }

    /**
     * @param  iterable<int, string>  $lignes
     */
    public function puces(iterable $lignes, int $taille = 1200, string $couleur = '3F3F46'): self
    {
        foreach ($lignes as $ligne) {
            $this->ligne($ligne, $taille, $couleur, puce: true);
        }

        return $this;
    }

    public function xml(int $id): string
    {
        $corps = '';

        foreach ($this->paragraphes as $paragraphe) {
            $corps .= $this->paragraphe($paragraphe);
        }

        if ($corps === '') {
            $corps = '<a:p><a:endParaRPr lang="fr-FR"/></a:p>';
        }

        $remplissage = $this->fondHexa === null
            ? '<a:noFill/>'
            : '<a:solidFill><a:srgbClr val="'.$this->fondHexa.'"/></a:solidFill>';

        $ancrage = $this->centre ? 'ctr' : 't';

        return <<<XML
            <p:sp>
            <p:nvSpPr><p:cNvPr id="{$id}" name="Bloc {$id}"/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>
            <p:spPr>
            <a:xfrm><a:off x="{$this->x}" y="{$this->y}"/><a:ext cx="{$this->largeur}" cy="{$this->hauteur}"/></a:xfrm>
            <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
            {$remplissage}
            </p:spPr>
            <p:txBody>
            <a:bodyPr wrap="square" lIns="91440" tIns="45720" rIns="91440" bIns="45720" anchor="{$ancrage}"><a:normAutofit/></a:bodyPr>
            <a:lstStyle/>
            {$corps}
            </p:txBody>
            </p:sp>
            XML;
    }

    /**
     * @param  array{texte: string, puce: bool, taille: int, couleur: string, gras: bool}  $paragraphe
     */
    private function paragraphe(array $paragraphe): string
    {
        $texte = self::echapper($paragraphe['texte']);
        $gras = $paragraphe['gras'] ? '1' : '0';

        // Une puce se déclare par l'absence de `buNone`, avec un retrait qui
        // aligne le texte des lignes qui passent à la ligne suivante.
        $proprietes = $paragraphe['puce']
            ? '<a:pPr marL="171450" indent="-171450"><a:buFont typeface="Arial"/><a:buChar char="•"/></a:pPr>'
            : '<a:pPr><a:buNone/></a:pPr>';

        if ($paragraphe['texte'] === '') {
            return '<a:p>'.$proprietes.'<a:endParaRPr lang="fr-FR" sz="'.$paragraphe['taille'].'"/></a:p>';
        }

        return '<a:p>'.$proprietes
            .'<a:r><a:rPr lang="fr-FR" sz="'.$paragraphe['taille'].'" b="'.$gras.'" dirty="0">'
            .'<a:solidFill><a:srgbClr val="'.$paragraphe['couleur'].'"/></a:solidFill>'
            .'<a:latin typeface="Calibri"/></a:rPr>'
            .'<a:t>'.$texte.'</a:t></a:r></a:p>';
    }

    /**
     * Échappe pour XML et retire les caractères de contrôle, qu'OOXML refuse
     * et qu'une saisie collée depuis un traitement de texte peut transporter.
     */
    public static function echapper(string $texte): string
    {
        $texte = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $texte) ?? $texte;

        return htmlspecialchars($texte, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
