<?php
/**
 * Export des activités vers un classeur Excel au format « PTA » (plan de travail annuel) :
 * même mise en forme que le fichier PTA chargé (PTA-2026-DPIP) — styles, couleurs, polices,
 * volets figés, en-tête des mois, 48 semaines (4 par mois), puis RESPONSABLE / OBSERVATIONS /
 * LIVRABLES / BESOINS. Les activités sont regroupées par direction / service, chacun avec ses
 * rubriques « ACTIVITÉS COURANTES » et « ACTIVITÉS INNOVANTES ».
 *
 * La mise en forme vient du modèle includes/modele_pta/ (extrait du fichier PTA d'origine).
 * Nécessite l'extension PHP « zip » (comme tout fichier .xlsx).
 */

require_once __DIR__ . '/xlsx.php';

const PTA_NB_SEMAINES = 48;          // 12 mois x 4 semaines
const PTA_DERNIERE_COLONNE = 55;     // BC

/** Texte propre pour une cellule XML (caractères de contrôle retirés, longueur Excel respectée). */
function ptaTexte(?string $t): string
{
    $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$t);
    return mb_substr($t, 0, 32000);
}

/** Cellule texte (chaîne en ligne) ou cellule vide stylée. */
function ptaCellule(string $col, int $ligne, int $style, string $texte = ''): string
{
    $ref = $col . $ligne;
    if ($texte === '') {
        return '<c r="' . $ref . '" s="' . $style . '"/>';
    }
    return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . xlsxEchapper(ptaTexte($texte)) . '</t></is></c>';
}

/** N° de semaine 1..48 d'une date : 4 semaines par mois (jours 1-7, 8-14, 15-21, 22 et plus). */
function ptaIndexSemaine(DateTimeInterface $d): int
{
    $mois = (int)$d->format('n');
    $semaine = min(3, intdiv((int)$d->format('j') - 1, 7));
    return ($mois - 1) * 4 + $semaine + 1;
}

/** Semaines (1..48) couvertes par une période, limitées à l'année $annee. Vide si hors année. */
function ptaSemainesActives(?string $debut, ?string $fin, int $annee): array
{
    if (!$debut || !$fin) {
        return [];
    }
    try {
        $d = new DateTimeImmutable($debut);
        $f = new DateTimeImmutable($fin);
    } catch (Throwable $e) {
        return [];
    }
    $a0 = new DateTimeImmutable("$annee-01-01");
    $a1 = new DateTimeImmutable("$annee-12-31");
    if ($f < $a0 || $d > $a1) {
        return [];
    }
    if ($d < $a0) $d = $a0;
    if ($f > $a1) $f = $a1;
    $s0 = ptaIndexSemaine($d);
    $s1 = ptaIndexSemaine($f);
    return $s1 >= $s0 ? range($s0, $s1) : [];
}

/** Retire un éventuel préfixe de numérotation déjà présent dans le libellé (« Activité 1: », « Tache 1.1.2 : »...). */
function ptaLibelleNettoye(string $libelle): string
{
    $l = trim(preg_replace('/\s+/u', ' ', $libelle));
    // tolère les fautes de frappe courantes (« Sous-activté », « Sous activité3.3: »...)
    $sans = preg_replace('/^(sous[\s-]*act\p{L}*|activit\p{L}*|t[aâ]che)\s*[0-9A-Za-z.]*\s*:\s*/iu', '', $l);
    $sans = ltrim($sans, " :");
    $sans = preg_replace('/^\d{1,3}(?:\.\d{1,3})*\s*:\s*/u', '', trim($sans));   // « 7 : », « 3.1 : »
    return trim($sans) ?: $l;
}

/** Années proposées pour l'export (du premier début au dernier fin d'activité, + année en cours). */
function ptaAnneesDisponibles(PDO $pdo): array
{
    $lig = $pdo->query('SELECT MIN(YEAR(Date_debut)) a, MAX(YEAR(Date_fin)) b FROM activites')->fetch();
    $min = (int)($lig['a'] ?: date('Y'));
    $max = (int)($lig['b'] ?: date('Y'));
    $cur = (int)date('Y');
    $min = min($min, $cur);
    $max = max($max, $cur);
    return range($max, $min);   // décroissant : l'année la plus récente d'abord
}

/**
 * Charge les activités de l'année $annee, organisées par direction / service.
 * @return array{blocs: list<array>, nbActivites: int}
 *   bloc = ['dept' => [id, nom, libelle, type] , 'racines' => list<noeud>] ; noeud = ligne d'activité + 'enfants'
 */
function ptaCharger(PDO $pdo, int $annee, ?int $deptId = null): array
{
    $depts = $pdo->query('SELECT id_departement, Nom_court, Libelle, Types FROM departement ORDER BY id_departement')->fetchAll();

    $acts = $pdo->query(
        "SELECT a.ID__activites AS id, a.Libelle, a.Observation, a.Types, a.Produit, a.pere_ID, a.Date_debut, a.Date_fin,
                a.Statut, a.ID_departement, ag.Prenom AS ag_prenom, ag.Nom AS ag_nom, d.Nom_court AS ag_dept
         FROM activites a
         JOIN agent_dpip ag ON ag.ID_agent_dpip = a.ID_agent_dpip
         LEFT JOIN departement d ON d.id_departement = ag.ID_departement
         ORDER BY a.Date_debut, a.ID__activites"
    )->fetchAll();
    $parId = [];
    foreach ($acts as $a) { $parId[(int)$a['id']] = $a; }

    $besoins = [];
    foreach ($pdo->query('SELECT ID_activites, libelle, Type, Estimation_financiere FROM besions ORDER BY id_besions') as $b) {
        $besoins[(int)$b['ID_activites']][] = $b;
    }
    $livrables = [];
    foreach ($pdo->query('SELECT ID_activites, libelle FROM livrables ORDER BY id_livrable') as $l) {
        $livrables[(int)$l['ID_activites']][] = $l['libelle'];
    }

    // arbre
    $enfants = [];
    foreach ($acts as $a) {
        $p = $a['pere_ID'] !== null ? (int)$a['pere_ID'] : 0;
        if ($p && !isset($parId[$p])) $p = 0;     // parent introuvable : traité comme élément de tête
        $enfants[$p][] = (int)$a['id'];
    }
    $construire = function (int $id) use (&$construire, &$enfants, &$parId, $annee, $besoins, $livrables): ?array {
        $a = $parId[$id];
        $noeud = $a + ['semaines' => ptaSemainesActives($a['Date_debut'], $a['Date_fin'], $annee), 'enfants' => []];
        foreach ($enfants[$id] ?? [] as $cid) {
            $c = $construire($cid);
            if ($c !== null) $noeud['enfants'][] = $c;
        }
        if (!$noeud['semaines'] && !$noeud['enfants']) {
            return null;                           // ni lui ni ses éléments ne concernent l'année
        }
        $noeud['besoins'] = $besoins[$id] ?? [];
        $noeud['livrables'] = $livrables[$id] ?? [];
        return $noeud;
    };

    // regroupement par service du ELEMENT DE TETE
    $parDept = [];
    $nb = 0;
    foreach ($enfants[0] ?? [] as $rid) {
        $n = $construire($rid);
        if ($n === null) continue;
        $d = $n['ID_departement'] !== null ? (int)$n['ID_departement'] : 0;
        $parDept[$d][] = $n;
    }
    $compter = function (array $liste) use (&$compter): int {
        $t = 0; foreach ($liste as $n) { $t += 1 + $compter($n['enfants']); } return $t;
    };

    $blocs = [];
    foreach ($depts as $d) {
        $id = (int)$d['id_departement'];
        if ($deptId !== null && $id !== $deptId) continue;
        $racines = $parDept[$id] ?? [];
        $blocs[] = ['dept' => ['id' => $id, 'nom' => $d['Nom_court'], 'libelle' => $d['Libelle'], 'type' => $d['Types']], 'racines' => $racines, 'nb' => $compter($racines)];
        $nb += $compter($racines);
    }
    if ($deptId === null && !empty($parDept[0])) {
        $blocs[] = ['dept' => ['id' => 0, 'nom' => 'SANS SERVICE', 'libelle' => '', 'type' => ''], 'racines' => $parDept[0], 'nb' => $compter($parDept[0])];
        $nb += $compter($parDept[0]);
    }
    return ['blocs' => $blocs, 'nbActivites' => $nb];
}

/** Texte de la colonne BESOINS : une ligne par besoin (« * Humain : ... »). */
function ptaTexteBesoins(array $besoins): string
{
    $types = ['materiel' => 'Matériel', 'humain' => 'Humain', 'financier' => 'Financier'];
    $l = [];
    foreach ($besoins as $b) {
        $t = '* ' . ($types[$b['Type']] ?? $b['Type']) . ' : ' . $b['libelle'];
        if ((int)$b['Estimation_financiere'] > 0) {
            $t .= ' (' . number_format((int)$b['Estimation_financiere'], 0, ',', ' ') . ' FCFA)';
        }
        $l[] = $t;
    }
    return implode("\n", $l);
}

/** Une ligne d'activité / sous-activité / tâche au format PTA. */
function ptaLigneActivite(int $r, array $n, string $numero, int $niveau, bool $colorer = true): string
{
    // Style de la colonne B (police, indentation) d'après le TYPE, comme dans le PTA d'origine : activité s30,
    // sous-activité s38 (retrait 1), tâche s39 (retrait 2) — même pour une tâche placée directement sous une activité.
    $styleB = match ($n['Types']) { 'activite' => 30, 'sous-activite' => 38, default => 39 };
    $prefixe = match ($n['Types']) {
        'activite' => 'Activité ' . $numero . ': ',
        'sous-activite' => 'Sous-activité ' . $numero . ': ',
        default => 'Tache ' . $numero . ' : ',
    };
    $x = '<row r="' . $r . '" spans="2:55" s="8" customFormat="1" x14ac:dyDescent="0.35">';
    $x .= ptaCellule('B', $r, $styleB, $prefixe . ptaLibelleNettoye($n['Libelle']));
    // Semaines colorées en bleu = période de l'activité. Une ligne non colorée garde malgré tout
    // les repères de début de mois (cellules bordées), comme les lignes de tâches du PTA d'origine.
    $sem = $colorer ? array_flip($n['semaines']) : [];
    for ($w = 1; $w <= PTA_NB_SEMAINES; $w++) {
        $col = xlsxIndexVersColonne(1 + $w);        // semaine 1 => colonne C
        $debutMois = (($w - 1) % 4 === 0);
        if (isset($sem[$w])) {
            $x .= ptaCellule($col, $r, $w === 1 ? 31 : ($debutMois ? 33 : 32));
        } elseif ($debutMois) {
            $x .= ptaCellule($col, $r, $w === 1 ? 7 : 34);
        }
    }
    $resp = trim($n['ag_prenom'] . ' ' . $n['ag_nom']) . ($n['ag_dept'] ? ' (' . $n['ag_dept'] . ')' : '');
    $liv = [];
    if (trim((string)$n['Produit']) !== '') $liv[] = trim($n['Produit']);
    foreach ($n['livrables'] as $l) $liv[] = $l;
    $x .= ptaCellule('AY', $r, 36, $resp);
    $x .= ptaCellule('AZ', $r, 36, trim((string)$n['Observation']));
    $x .= ptaCellule('BA', $r, 36, implode(' ; ', $liv));
    $x .= ptaCellule('BB', $r, 37, ptaTexteBesoins($n['besoins']));
    $x .= ptaCellule('BC', $r, 7);
    return $x . '</row>';
}

/** Génère les lignes d'un bloc (service) : titre, ACTIVITÉS COURANTES, activités, ACTIVITÉS INNOVANTES. */
function ptaLignesBloc(array $bloc, int &$r, array $modele): string
{
    $remplir = function (string $gab, string $texte, int $ligne): string {
        return str_replace(['{{R}}', '{{TEXTE}}'], [(string)$ligne, xlsxEchapper(ptaTexte($texte))], $gab);
    };
    $x = $remplir($modele['service'], $bloc['dept']['nom'], $r); $r++;
    $x .= $remplir($modele['courantes'], 'ACTIVITÉS COURANTES', $r); $r++;

    $i = 0;
    foreach ($bloc['racines'] as $racine) {
        $i++;
        $parcourir = function (array $n, string $numero, int $niveau) use (&$parcourir, &$r, &$x) {
            // Semaines colorées en bleu : uniquement pour les activités, sous-activités et tâches TERMINÉES,
            // sur la période pour laquelle elles ont été définies (dates de début et de fin).
            $colorer = ($n['Statut'] ?? '') === 'termine';
            $x .= ptaLigneActivite($r, $n, $numero, min($niveau, 2), $colorer); $r++;
            $k = 0;
            foreach ($n['enfants'] as $c) {
                $k++;
                $parcourir($c, $numero . '.' . $k, $niveau + 1);
            }
        };
        $parcourir($racine, (string)$i, 0);
    }
    $x .= $remplir($modele['innovantes'], 'ACTIVITÉS INNOVANTES', $r); $r++;
    return $x;
}

/**
 * Écrit le classeur PTA dans $chemin.
 * Semaines colorées en bleu = période des activités, sous-activités et tâches au statut « Terminé ».
 * Le classeur est livré SANS filtre automatique (comme le PTA d'origine).
 * @return array{nbActivites:int, nbBlocs:int, nbLignes:int, feuille:string}
 */
function ptaGenererClasseur(PDO $pdo, string $chemin, int $annee, ?int $deptId = null): array
{
    if (!extension_loaded('zip')) {
        throw new RuntimeException("L'extension PHP « zip » n'est pas activée sur ce serveur : impossible de générer un fichier .xlsx.");
    }
    $dir = __DIR__ . '/modele_pta';
    $modele = json_decode((string)file_get_contents($dir . '/modele.json'), true);
    if (!is_array($modele)) {
        throw new RuntimeException('Modèle PTA introuvable (includes/modele_pta/modele.json).');
    }

    $donnees = ptaCharger($pdo, $annee, $deptId);

    $r = 3;
    $lignes = '';
    foreach ($donnees['blocs'] as $bloc) {
        $lignes .= ptaLignesBloc($bloc, $r, $modele);
    }
    $derniere = $r - 1;

    $entete2 = str_replace('{{ANNEE}}', (string)$annee, $modele['entete2']);
    $fusions = '';
    foreach ($modele['fusionsEntete'] as $f) { $fusions .= '<mergeCell ref="' . $f . '"/>'; }

    $nomPortee = 'DPIP';
    if ($deptId !== null && !empty($donnees['blocs'])) {
        $nomPortee = $donnees['blocs'][0]['dept']['nom'];
    }
    $nomFeuille = mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/u', '-', 'PTA-' . $annee . '-' . $nomPortee), 0, 31);

    $feuille = $modele['racine']
        . '<dimension ref="B1:BC' . $derniere . '"/>'
        . $modele['sheetViews'] . $modele['sheetFormatPr'] . $modele['cols']
        . '<sheetData>' . $modele['entete1'] . $entete2 . $lignes . '</sheetData>'
        . '<mergeCells count="' . count($modele['fusionsEntete']) . '">' . $fusions . '</mergeCells>'
        . $modele['pageMargins'] . $modele['pageSetup']
        . '</worksheet>';

    $ns = 'http://schemas.openxmlformats.org/';
    $classeur = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="' . $ns . 'spreadsheetml/2006/main" xmlns:r="' . $ns . 'officeDocument/2006/relationships">'
        . '<workbookPr defaultThemeVersion="166925"/>'
        . '<bookViews><workbookView xWindow="-108" yWindow="-108" windowWidth="23256" windowHeight="12456"/></bookViews>'
        . '<sheets><sheet name="' . xlsxEchapper($nomFeuille) . '" sheetId="1" r:id="rId1"/></sheets>'
        . '<definedNames>'
        . '<definedName name="_xlnm.Print_Area" localSheetId="0">\'' . xlsxEchapper($nomFeuille) . '\'!$B$1:$AZ$' . $derniere . '</definedName>'
        . '</definedNames><calcPr calcId="191029"/></workbook>';

    if (file_exists($chemin)) unlink($chemin);
    $zip = new ZipArchive();
    if ($zip->open($chemin, ZipArchive::CREATE) !== true) {
        throw new RuntimeException('Impossible de créer le fichier .xlsx.');
    }
    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="' . $ns . 'package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>');
    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="' . $ns . 'package/2006/relationships">'
        . '<Relationship Id="rId1" Type="' . $ns . 'officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="' . $ns . 'package/2006/relationships">'
        . '<Relationship Id="rId1" Type="' . $ns . 'officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="' . $ns . 'officeDocument/2006/relationships/theme" Target="theme/theme1.xml"/>'
        . '<Relationship Id="rId3" Type="' . $ns . 'officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/workbook.xml', $classeur);
    $zip->addFromString('xl/worksheets/sheet1.xml', $feuille);
    $zip->addFile($dir . '/styles.xml', 'xl/styles.xml');
    $zip->addFile($dir . '/theme1.xml', 'xl/theme/theme1.xml');
    $zip->close();

    return ['nbActivites' => $donnees['nbActivites'], 'nbBlocs' => count($donnees['blocs']), 'nbLignes' => $derniere, 'feuille' => $nomFeuille];
}

/** Nom de fichier ASCII sûr (sans accents ni espaces) pour un nom de direction / service. */
function ptaNomFichier(string $nom): string
{
    $s = strtr($nom, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ç' => 'c',
                      'É' => 'E', 'È' => 'E', 'À' => 'A']);
    $s = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-');
    return $s !== '' ? $s : 'SERVICE';
}

/**
 * Archive ZIP contenant le fichier GLOBAL (toutes directions et tous services) puis un fichier PTA par
 * direction / service qui a au moins une activité sur l'année. Tous au même format que le PTA d'origine.
 * Toutes les activités de la base y figurent, qu'elles aient été importées ou saisies à la main.
 * @return array{fichiers: list<array{nom:string, nbActivites:int}>, nbActivites:int}
 */
function ptaGenererArchive(PDO $pdo, string $cheminZip, int $annee): array
{
    if (!extension_loaded('zip')) {
        throw new RuntimeException("L'extension PHP « zip » n'est pas activée sur ce serveur : impossible de générer un fichier .xlsx.");
    }
    $zip = new ZipArchive();
    if (file_exists($cheminZip)) unlink($cheminZip);
    if ($zip->open($cheminZip, ZipArchive::CREATE) !== true) {
        throw new RuntimeException("Impossible de créer l'archive ZIP.");
    }
    $temporaires = [];
    $fichiers = [];
    try {
        $ajouter = function (string $nomDansZip, ?int $deptId) use ($pdo, $annee, $zip, &$temporaires, &$fichiers): array {
            $tmp = tempnam(sys_get_temp_dir(), 'pta_zip_') . '.xlsx';
            $temporaires[] = $tmp;
            $r = ptaGenererClasseur($pdo, $tmp, $annee, $deptId);
            $zip->addFile($tmp, $nomDansZip);
            $fichiers[] = ['nom' => $nomDansZip, 'nbActivites' => $r['nbActivites']];
            return $r;
        };
        $global = $ajouter('PTA-' . $annee . '-GLOBAL-toutes-directions.xlsx', null);
        foreach (ptaCharger($pdo, $annee, null)['blocs'] as $bloc) {
            if ($bloc['nb'] > 0 && $bloc['dept']['id'] > 0) {
                $ajouter('PTA-' . $annee . '-' . ptaNomFichier($bloc['dept']['nom']) . '.xlsx', (int)$bloc['dept']['id']);
            }
        }
        $zip->close();
    } catch (Throwable $e) {
        @$zip->close();
        @unlink($cheminZip);
        throw $e;
    } finally {
        foreach ($temporaires as $t) { @unlink($t); }
    }
    return ['fichiers' => $fichiers, 'nbActivites' => $global['nbActivites']];
}
