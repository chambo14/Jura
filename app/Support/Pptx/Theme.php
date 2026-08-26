<?php

namespace App\Support\Pptx;

/**
 * Thème minimal mais complet.
 *
 * PowerPoint refuse d'ouvrir une présentation dont le thème est incomplet :
 * il exige les six couleurs d'accentuation, les deux jeux de polices et les
 * trois listes de formats (remplissages, contours, effets). Ce fichier ne
 * cherche pas à être joli — les couleurs réelles sont posées forme par forme
 * — mais à être valide.
 */
class Theme
{
    public static function xml(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Suivi Projets MPM">
            <a:themeElements>
            <a:clrScheme name="MPM">
            <a:dk1><a:sysClr val="windowText" lastClr="000000"/></a:dk1>
            <a:lt1><a:sysClr val="window" lastClr="FFFFFF"/></a:lt1>
            <a:dk2><a:srgbClr val="18181B"/></a:dk2>
            <a:lt2><a:srgbClr val="F4F4F5"/></a:lt2>
            <a:accent1><a:srgbClr val="2563EB"/></a:accent1>
            <a:accent2><a:srgbClr val="16A34A"/></a:accent2>
            <a:accent3><a:srgbClr val="D97706"/></a:accent3>
            <a:accent4><a:srgbClr val="DC2626"/></a:accent4>
            <a:accent5><a:srgbClr val="7C3AED"/></a:accent5>
            <a:accent6><a:srgbClr val="0891B2"/></a:accent6>
            <a:hlink><a:srgbClr val="2563EB"/></a:hlink>
            <a:folHlink><a:srgbClr val="7C3AED"/></a:folHlink>
            </a:clrScheme>
            <a:fontScheme name="MPM">
            <a:majorFont><a:latin typeface="Calibri Light"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont>
            <a:minorFont><a:latin typeface="Calibri"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont>
            </a:fontScheme>
            <a:fmtScheme name="MPM">
            <a:fillStyleLst>
            <a:solidFill><a:schemeClr val="phClr"/></a:solidFill>
            <a:solidFill><a:schemeClr val="phClr"/></a:solidFill>
            <a:solidFill><a:schemeClr val="phClr"/></a:solidFill>
            </a:fillStyleLst>
            <a:lnStyleLst>
            <a:ln w="6350" cap="flat" cmpd="sng" algn="ctr"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:prstDash val="solid"/></a:ln>
            <a:ln w="12700" cap="flat" cmpd="sng" algn="ctr"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:prstDash val="solid"/></a:ln>
            <a:ln w="19050" cap="flat" cmpd="sng" algn="ctr"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:prstDash val="solid"/></a:ln>
            </a:lnStyleLst>
            <a:effectStyleLst>
            <a:effectStyle><a:effectLst/></a:effectStyle>
            <a:effectStyle><a:effectLst/></a:effectStyle>
            <a:effectStyle><a:effectLst/></a:effectStyle>
            </a:effectStyleLst>
            <a:bgFillStyleLst>
            <a:solidFill><a:schemeClr val="phClr"/></a:solidFill>
            <a:solidFill><a:schemeClr val="phClr"/></a:solidFill>
            <a:solidFill><a:schemeClr val="phClr"/></a:solidFill>
            </a:bgFillStyleLst>
            </a:fmtScheme>
            </a:themeElements>
            <a:objectDefaults/>
            <a:extraClrSchemeLst/>
            </a:theme>
            XML;
    }
}
