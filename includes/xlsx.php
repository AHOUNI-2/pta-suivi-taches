<?php
/**
 * Lecture / écriture minimaliste de fichiers .xlsx, sans dépendance externe
 * (le format .xlsx est une archive ZIP contenant du XML : on s'appuie
 * uniquement sur ZipArchive + SimpleXML, tous deux inclus dans PHP).
 * Se limite à ce dont l'import d'activités a besoin : une seule feuille,
 * cellules texte / nombre / date, pas de mise en forme.
 */

/** Convertit une référence de colonne Excel ('A', 'B', ..., 'AA') en index 0-based. */
function xlsxColonneVersIndex(string $lettres): int
{
    $lettres = strtoupper($lettres);
    $index = 0;
    for ($i = 0; $i < strlen($lettres); $i++) {
        $index = $index * 26 + (ord($lettres[$i]) - ord('A') + 1);
    }
    return $index - 1;
}

/** Convertit un numéro de série de date Excel (base 1900) en date 'Y-m-d'. */
function xlsxSerieVersDate(float $serie): string
{
    $unix = (int)round(($serie - 25569) * 86400);
    return gmdate('Y-m-d', $unix);
}

/**
 * Lit la première feuille d'un fichier .xlsx et renvoie un tableau de lignes ;
 * chaque ligne est un tableau de valeurs texte (index 0-based par colonne,
 * cellules vides comprises jusqu'à la dernière colonne utilisée de la ligne).
 * Lève une exception si le fichier n'est pas un .xlsx valide.
 */
function xlsxLireFeuille(string $chemin): array
{
    if (!extension_loaded('zip')) {
        throw new RuntimeException("L'extension PHP « zip » n'est pas activée sur ce serveur : les fichiers .xlsx ne peuvent pas être lus (utilisez un fichier .csv).");
    }
    $zip = new ZipArchive();
    if ($zip->open($chemin) !== true) {
        throw new RuntimeException("Impossible d'ouvrir le fichier .xlsx (archive invalide).");
    }

    $partagees = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sx = simplexml_load_string($sharedXml);
        if ($sx !== false) {
            foreach ($sx->si as $si) {
                if (isset($si->t)) {
                    $partagees[] = (string)$si->t;
                } else {
                    $texte = '';
                    foreach ($si->r as $r) {
                        $texte .= (string)$r->t;
                    }
                    $partagees[] = $texte;
                }
            }
        }
    }

    $feuilleXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($feuilleXml === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nom = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $nom)) {
                $feuilleXml = $zip->getFromName($nom);
                break;
            }
        }
    }
    $zip->close();

    if ($feuilleXml === false) {
        throw new RuntimeException('Aucune feuille exploitable trouvée dans le fichier .xlsx.');
    }

    $sheet = simplexml_load_string($feuilleXml);
    $lignes = [];
    foreach ($sheet->sheetData->row as $row) {
        $numLigne = (int)$row['r'] - 1;
        $ligne = [];
        foreach ($row->c as $cell) {
            $ref = (string)$cell['r'];
            preg_match('/^([A-Z]+)/', $ref, $m);
            $col = $m[1] ?? 'A';
            $idx = xlsxColonneVersIndex($col);
            $type = (string)$cell['t'];

            if ($type === 's') {
                $valeur = $partagees[(int)$cell->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $valeur = (string)($cell->is->t ?? '');
            } elseif ($type === 'str') {
                $valeur = (string)$cell->v;
            } else {
                $valeur = (string)$cell->v;
            }
            $ligne[$idx] = $valeur;
        }
        if (!empty($ligne)) {
            $max = max(array_keys($ligne));
            $ligneComplete = [];
            for ($i = 0; $i <= $max; $i++) {
                $ligneComplete[$i] = $ligne[$i] ?? '';
            }
            $lignes[$numLigne] = $ligneComplete;
        }
    }

    ksort($lignes);
    return array_values($lignes);
}

/** Échappe une valeur pour l'insérer comme texte inline dans le XML d'une feuille .xlsx. */
function xlsxEchapper(string $valeur): string
{
    return htmlspecialchars($valeur, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Écrit un fichier .xlsx à une seule feuille, avec $entetes en première ligne
 * (en gras) puis une ligne par élément de $lignes (tableaux de chaînes).
 * Toutes les cellules sont écrites en texte (inlineStr) : suffisant pour un
 * modèle à remplir, sans typage de cellule à gérer côté Excel.
 */
function xlsxEcrireFeuille(string $chemin, array $entetes, array $lignes): void
{
    if (!extension_loaded('zip')) {
        throw new RuntimeException("L'extension PHP « zip » n'est pas activée sur ce serveur : impossible de générer un fichier .xlsx (utilisez le modèle .csv).");
    }
    if (file_exists($chemin)) {
        unlink($chemin);
    }
    $zip = new ZipArchive();
    if ($zip->open($chemin, ZipArchive::CREATE) !== true) {
        throw new RuntimeException("Impossible de créer le fichier .xlsx.");
    }

    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
        '<Default Extension="xml" ContentType="application/xml"/>' .
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
        '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
        '</Types>');

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
        '</Relationships>');

    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
        '</Relationships>');

    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<sheets><sheet name="Activités" sheetId="1" r:id="rId1"/></sheets>' .
        '</workbook>');

    $toutesLignes = array_merge([$entetes], $lignes);
    $xmlLignes = '';
    foreach ($toutesLignes as $numLigne => $ligne) {
        $r = $numLigne + 1;
        $xmlLignes .= '<row r="' . $r . '">';
        foreach (array_values($ligne) as $c => $valeur) {
            $colLettre = xlsxIndexVersColonne($c);
            $xmlLignes .= '<c r="' . $colLettre . $r . '" t="inlineStr"><is><t xml:space="preserve">' . xlsxEchapper((string)$valeur) . '</t></is></c>';
        }
        $xmlLignes .= '</row>';
    }

    $zip->addFromString('xl/worksheets/sheet1.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
        '<sheetData>' . $xmlLignes . '</sheetData>' .
        '</worksheet>');

    $zip->close();
}

/**
 * Écrit un fichier .xlsx à PLUSIEURS feuilles. $feuilles est un tableau associatif
 * nom de feuille => ['entetes' => [...], 'lignes' => [[...], ...]] (mêmes règles
 * que xlsxEcrireFeuille, une feuille par entrée, dans l'ordre du tableau).
 */
function xlsxEcrireClasseur(string $chemin, array $feuilles): void
{
    if (!extension_loaded('zip')) {
        throw new RuntimeException("L'extension PHP « zip » n'est pas activée sur ce serveur : impossible de générer un fichier .xlsx (utilisez le modèle .csv).");
    }
    if (file_exists($chemin)) {
        unlink($chemin);
    }
    $zip = new ZipArchive();
    if ($zip->open($chemin, ZipArchive::CREATE) !== true) {
        throw new RuntimeException("Impossible de créer le fichier .xlsx.");
    }

    $overrides = '';
    $sheetsXml = '';
    $relsXml = '';
    $n = 0;
    foreach ($feuilles as $nomFeuille => $contenu) {
        $n++;
        $overrides .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $sheetsXml .= '<sheet name="' . xlsxEchapper((string)$nomFeuille) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $relsXml .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';

        $toutesLignes = array_merge([$contenu['entetes']], $contenu['lignes']);
        $xmlLignes = '';
        foreach ($toutesLignes as $numLigne => $ligne) {
            $r = $numLigne + 1;
            $xmlLignes .= '<row r="' . $r . '">';
            foreach (array_values($ligne) as $c => $valeur) {
                $colLettre = xlsxIndexVersColonne($c);
                $xmlLignes .= '<c r="' . $colLettre . $r . '" t="inlineStr"><is><t xml:space="preserve">' . xlsxEchapper((string)$valeur) . '</t></is></c>';
            }
            $xmlLignes .= '</row>';
        }
        $zip->addFromString('xl/worksheets/sheet' . $n . '.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<sheetData>' . $xmlLignes . '</sheetData>' .
            '</worksheet>');
    }

    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
        '<Default Extension="xml" ContentType="application/xml"/>' .
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
        $overrides .
        '</Types>');

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
        '</Relationships>');

    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        $relsXml .
        '</Relationships>');

    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<sheets>' . $sheetsXml . '</sheets>' .
        '</workbook>');

    $zip->close();
}

/** Convertit un index de colonne 0-based en référence Excel ('A', 'B', ..., 'AA'). */
function xlsxIndexVersColonne(int $index): string
{
    $index++;
    $lettres = '';
    while ($index > 0) {
        $reste = ($index - 1) % 26;
        $lettres = chr(65 + $reste) . $lettres;
        $index = intdiv($index - 1, 26);
    }
    return $lettres;
}
