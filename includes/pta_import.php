<?php
/**
 * Import d'un fichier Excel au FORMAT PTA (plan de travail annuel) : le même format que celui produit par
 * l'export (page « Exporter vers Excel ») et que le fichier PTA de la DPIP.
 *
 *   col. B        : « Activité 1: … », « Sous-activité 1.1: … », « Tache 1.1.1 : … » (+ titres de service et de rubrique)
 *   col. C à AX   : 48 semaines (4 par mois) ; une cellule colorée = semaine dans la période de la ligne
 *   col. AY à BB  : RESPONSABLE, OBSERVATIONS, LIVRABLES, BESOINS
 *
 * Deux temps : ptaLireClasseur() ANALYSE le fichier (aucune écriture en base), ptaImporter() CRÉE les activités
 * après confirmation et choix du service, du responsable et de l'agent de chaque bloc.
 */

const PTA_IMPORT_MAX_LIGNES = 3000;
const PTA_PREMIERE_SEMAINE_COL = 3;    // C
const PTA_DERNIERE_SEMAINE_COL = 50;   // AX

/** Majuscules, sans accents, mots séparés par une espace (comparaison de libellés et de noms). */
function ptaNormaliser(string $s): string
{
    $s = mb_strtoupper(trim($s), 'UTF-8');
    $s = strtr($s, ['É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Î' => 'I', 'Ï' => 'I',
                    'Ô' => 'O', 'Ö' => 'O', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ç' => 'C', 'Œ' => 'OE', "’" => "'"]);
    return trim(preg_replace('/[^A-Z0-9]+/', ' ', $s));
}

/** Index de colonne (1 = A) d'une référence de cellule « AZ12 ». */
function ptaColonne(string $ref): int
{
    preg_match('/^([A-Z]+)/', $ref, $m);
    $n = 0;
    foreach (str_split($m[1] ?? 'A') as $c) { $n = $n * 26 + ord($c) - 64; }
    return $n;
}

/** Début et fin (AAAA-MM-JJ) de la semaine $w (1..48) de l'année $annee : jours 1-7, 8-14, 15-21, 22 et plus du mois. */
function ptaSemaineDates(int $w, int $annee): array
{
    $mois = intdiv($w - 1, 4) + 1;
    $k = ($w - 1) % 4;
    $dernier = (int)date('t', mktime(0, 0, 0, $mois, 1, $annee));
    return [
        sprintf('%04d-%02d-%02d', $annee, $mois, [1, 8, 15, 22][$k]),
        sprintf('%04d-%02d-%02d', $annee, $mois, [7, 14, 21, $dernier][$k]),
    ];
}

/** Analyse le libellé de la colonne B : type, numéro et texte, ou rubrique ; null si le texte n'est pas une activité. */
function ptaClasserLibelle(string $b): ?array
{
    $t = trim(preg_replace('/\s+/u', ' ', $b));
    if ($t === '') {
        return null;
    }
    $n = ptaNormaliser($t);
    if ($n === 'ACTIVITES COURANTES' || $n === 'ACTIVITES INNOVANTES') {
        return ['section' => $n === 'ACTIVITES COURANTES' ? 'courantes' : 'innovantes'];
    }
    // tolère les fautes de frappe du PTA (« Sous-acttivité », « Sous activité3.3 », « Sous-activté : » sans numéro…)
    if (preg_match('/^sous[\s-]*act\p{L}*\s*([0-9A-Za-z.\-]*)\s*:\s*(.*)$/iu', $t, $m)) {
        return ['type' => 'sous-activite', 'numero' => str_replace('-', '.', rtrim($m[1], '.-')), 'libelle' => trim($m[2])];
    }
    if (preg_match('/^activit\p{L}*\s*([0-9A-Za-z.\-]*)\s*:\s*(.*)$/iu', $t, $m)) {
        return ['type' => 'activite', 'numero' => str_replace('-', '.', rtrim($m[1], '.-')), 'libelle' => trim($m[2])];
    }
    if (preg_match('/^t[aâ]che\s*([0-9A-Za-z.\-]*)\s*:\s*(.*)$/iu', $t, $m)) {
        return ['type' => 'tache', 'numero' => str_replace('-', '.', rtrim($m[1], '.-')), 'libelle' => trim($m[2])];
    }
    return null;
}

/** Une cellule dont le style a un fond plein non blanc est « colorée ». */
function ptaStylesColores(ZipArchive $zip): array
{
    $xml = $zip->getFromName('xl/styles.xml');
    if ($xml === false) {
        return [];
    }
    $st = simplexml_load_string($xml);
    if ($st === false) {
        return [];
    }
    $fillColore = [];
    foreach ($st->fills->fill ?? [] as $f) {
        $p = $f->patternFill;
        $colore = false;
        if ($p !== null && (string)$p['patternType'] === 'solid') {
            $fg = $p->fgColor;
            $colore = true;
            if ($fg !== null) {
                $rgb = strtoupper((string)$fg['rgb']);
                if (in_array($rgb, ['FFFFFFFF', '00FFFFFF'], true)) $colore = false;
                if (isset($fg['theme']) && (int)$fg['theme'] === 0 && (float)($fg['tint'] ?? 0) >= 0) $colore = false;   // blanc du thème
                if (isset($fg['indexed']) && in_array((int)$fg['indexed'], [64, 65], true)) $colore = false;          // « automatique »
            }
        }
        $fillColore[] = $colore;
    }
    $styles = [];
    $i = 0;
    foreach ($st->cellXfs->xf ?? [] as $xf) {
        $styles[$i++] = $fillColore[(int)$xf['fillId']] ?? false;
    }
    return $styles;
}

/** Chemin interne de la première feuille du classeur. */
function ptaPremiereFeuille(ZipArchive $zip): array
{
    $wb = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb === false || $rels === false) {
        throw new RuntimeException("Ce fichier n'est pas un classeur Excel (.xlsx) valide.");
    }
    $w = simplexml_load_string($wb);
    $r = simplexml_load_string($rels);
    if ($w === false || $r === false || !isset($w->sheets->sheet[0])) {
        throw new RuntimeException('Le classeur ne contient aucune feuille.');
    }
    $feuille = $w->sheets->sheet[0];
    $rid = (string)$feuille->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
    foreach ($r->Relationship as $rel) {
        if ((string)$rel['Id'] === $rid) {
            $cible = (string)$rel['Target'];
            return [ltrim(str_starts_with($cible, '/') ? $cible : 'xl/' . $cible, '/'), (string)$feuille['name']];
        }
    }
    throw new RuntimeException('Feuille introuvable dans le classeur.');
}

/**
 * ANALYSE un fichier PTA (aucune écriture en base).
 * @return array{annee:?int, feuille:string, blocs:list<array>, avertissements:list<string>, nbLignes:int}
 *   bloc  = ['nom' => 'SDPS', 'lignes' => list<ligne>]
 *   ligne = ['ligne' (n° Excel), 'type', 'numero', 'libelle', 'semaines' (1..48 colorées), 'observation', 'livrables',
 *            'besoins', 'responsable' (texte AY), 'parent' (index dans le bloc ou null), 'section']
 */
function ptaLireClasseur(string $chemin): array
{
    if (!extension_loaded('zip')) {
        throw new RuntimeException("L'extension PHP « zip » n'est pas activée sur ce serveur : impossible de lire un fichier .xlsx.");
    }
    $zip = new ZipArchive();
    if ($zip->open($chemin) !== true) {
        throw new RuntimeException("Ce fichier n'est pas un classeur Excel (.xlsx) lisible.");
    }
    [$cheminFeuille, $nomFeuille] = ptaPremiereFeuille($zip);
    $colores = ptaStylesColores($zip);

    // chaînes partagées
    $ss = [];
    $sx = $zip->getFromName('xl/sharedStrings.xml');
    if ($sx !== false && ($x = simplexml_load_string($sx)) !== false) {
        foreach ($x->si as $si) {
            $t = '';
            if (isset($si->t)) { $t = (string)$si->t; } else { foreach ($si->r as $r) { $t .= (string)$r->t; } }
            $ss[] = $t;
        }
    }

    // lecture en flux de la feuille : seule la mémoire d'une ligne est utilisée
    $tmp = tempnam(sys_get_temp_dir(), 'pta_imp_');
    file_put_contents($tmp, $zip->getFromName($cheminFeuille) ?: '');
    $reader = new XMLReader();
    if (!$reader->open($tmp)) {
        @unlink($tmp);
        throw new RuntimeException('Feuille illisible.');
    }
    $lignes = [];       // n° de ligne => données utiles
    try {
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') continue;
            $dom = new DOMDocument();
            $row = simplexml_import_dom($dom->importNode($reader->expand(), true));
            $r = (int)$row['r'];
            if ($r > PTA_IMPORT_MAX_LIGNES + 10) break;
            $d = ['B' => '', 'bColore' => false, 'sem' => [], 'AY' => '', 'AZ' => '', 'BA' => '', 'BB' => '', 'C' => ''];
            foreach ($row->c as $c) {
                $col = ptaColonne((string)$c['r']);
                $s = (int)$c['s'];
                $t = (string)$c['t'];
                if ($t === 's') { $v = $ss[(int)$c->v] ?? ''; }
                elseif ($t === 'inlineStr') { $v = ''; foreach ($c->is->t ?? [] as $tt) { $v .= (string)$tt; } if ($v === '' && isset($c->is->r)) { foreach ($c->is->r as $rr) { $v .= (string)$rr->t; } } }
                else { $v = isset($c->v) ? (string)$c->v : ''; }
                if ($col === 2) { $d['B'] = $v; $d['bColore'] = $colores[$s] ?? false; }
                elseif ($col === 3) { $d['C'] = $v; }
                if ($col >= PTA_PREMIERE_SEMAINE_COL && $col <= PTA_DERNIERE_SEMAINE_COL && ($colores[$s] ?? false)) { $d['sem'][] = $col - 2; }
                elseif ($col === 51) { $d['AY'] = $v; } elseif ($col === 52) { $d['AZ'] = $v; }
                elseif ($col === 53) { $d['BA'] = $v; } elseif ($col === 54) { $d['BB'] = $v; }
            }
            $lignes[$r] = $d;
        }
    } finally {
        $reader->close();
        @unlink($tmp);
    }

    // contrôle du format
    $entete = ptaNormaliser(($lignes[1]['B'] ?? ''));
    $mois = ptaNormaliser(($lignes[1]['C'] ?? ''));
    if (!str_contains($entete, 'PROGRAMME') && !str_contains($entete, 'ACTIVITE') && !str_starts_with($mois, 'JANV')) {
        throw new RuntimeException("Ce fichier n'est pas au format PTA : la ligne 1 doit contenir « PROGRAMME/ACTIVITE DE TRAVAIL » et les mois (Janv., Févr…), comme dans le fichier PTA de la DPIP.");
    }
    $annee = null;
    $b2 = trim((string)($lignes[2]['B'] ?? ''));
    if (preg_match('/^(20\d\d)(\.0+)?$/', $b2, $m)) { $annee = (int)$m[1]; }

    // blocs, rubriques, activités
    $blocs = [];
    $avertissements = [];
    $courant = null;                 // index du bloc courant
    $section = 'courantes';
    $parNumero = [];                 // numéro => index de ligne (activités et sous-activités du bloc courant)
    $derniereActivite = null; $derniereSousActivite = null;
    $nb = 0;
    $nomParDefaut = preg_match('/^PTA[-_ ]*\d{4}[-_ ]*(.+)$/i', $nomFeuille, $mm) ? trim($mm[1]) : 'Service non précisé';
    ksort($lignes);
    foreach ($lignes as $n => $d) {
        if ($n < 3) continue;
        $b = trim(preg_replace('/\s+/u', ' ', $d['B']));
        if ($b === '') continue;
        $c = ptaClasserLibelle($b);
        if ($c === null) {
            if ($d['bColore']) {                       // titre de direction / service (cellule colorée, comme « SDPS »)
                $blocs[] = ['nom' => $b, 'lignes' => []];
                $courant = count($blocs) - 1; $section = 'courantes';
                $parNumero = []; $derniereActivite = $derniereSousActivite = null;
            } else {
                $avertissements[] = "Ligne $n ignorée (texte non reconnu comme activité, sous-activité, tâche ou titre) : « " . mb_substr($b, 0, 60) . ' »';
            }
            continue;
        }
        if (isset($c['section'])) { $section = $c['section']; continue; }
        if ($courant === null) {                       // activités avant tout titre de service
            $blocs[] = ['nom' => $nomParDefaut, 'lignes' => []];
            $courant = 0;
        }
        if (++$nb > PTA_IMPORT_MAX_LIGNES) {
            throw new RuntimeException('Le fichier contient plus de ' . PTA_IMPORT_MAX_LIGNES . ' lignes d\'activités : scindez-le (un fichier par direction ou service).');
        }
        $lignesBloc = &$blocs[$courant]['lignes'];
        $idx = count($lignesBloc);
        $parent = null;
        $num = $c['numero'];
        if ($c['type'] !== 'activite') {
            // 1) par la numérotation : « 1.1.3 » → « 1.1 », sinon « 1 » ; 2) à défaut, par la position
            $parts = $num !== '' ? explode('.', $num) : [];
            for ($k = count($parts) - 1; $k >= 1 && $parent === null; $k--) {
                $cle = implode('.', array_slice($parts, 0, $k));
                if (isset($parNumero[$cle])) { $parent = $parNumero[$cle]; }
            }
            if ($parent === null) {
                $parent = $c['type'] === 'sous-activite' ? $derniereActivite : ($derniereSousActivite ?? $derniereActivite);
                if ($parent !== null && $num !== '') { /* numérotation non retrouvée : rattachement par position */ }
            }
            // une sous-activité se rattache toujours à une ACTIVITÉ
            if ($c['type'] === 'sous-activite') {
                while ($parent !== null && $lignesBloc[$parent]['type'] !== 'activite') { $parent = $lignesBloc[$parent]['parent']; }
            }
            if ($parent === null) {
                $avertissements[] = "Ligne $n : « " . mb_substr($c['libelle'], 0, 50) . ' » n\'a pas d\'élément parent au-dessus ; elle est créée sans rattachement.';
            }
        }
        $lignesBloc[] = [
            'ligne' => $n, 'type' => $c['type'], 'numero' => $num, 'libelle' => $c['libelle'],
            'semaines' => $d['sem'], 'observation' => trim($d['AZ']), 'livrables' => trim($d['BA']), 'besoins' => trim($d['BB']),
            'responsable' => trim($d['AY']), 'parent' => $parent, 'section' => $section,
        ];
        if ($c['type'] !== 'tache') { if ($num !== '') { $parNumero[$num] = $idx; } }
        if ($c['type'] === 'activite') { $derniereActivite = $idx; $derniereSousActivite = null; $parNumero = $num !== '' ? [$num => $idx] : []; }
        if ($c['type'] === 'sous-activite') { $derniereSousActivite = $idx; }
        unset($lignesBloc);
    }
    if ($nb === 0) {
        throw new RuntimeException("Aucune activité, sous-activité ni tâche n'a été reconnue dans ce fichier (les lignes doivent commencer par « Activité n: », « Sous-activité n.m: » ou « Tache n.m.k : »).");
    }
    return ['annee' => $annee, 'feuille' => $nomFeuille, 'blocs' => $blocs, 'avertissements' => $avertissements, 'nbLignes' => $nb];
}

/**
 * Période (en semaines 1..48) de chaque ligne d'un bloc :
 * semaines colorées ; sinon période du parent (le plus proche) ; sinon étendue des éléments rattachés ; sinon toute l'année.
 * @return list<array{debut:int, fin:int, source:string}>
 */
function ptaPeriodes(array $lignes): array
{
    $propre = [];
    foreach ($lignes as $i => $l) { $propre[$i] = $l['semaines'] ? [min($l['semaines']), max($l['semaines'])] : null; }
    $enfants = [];
    foreach ($lignes as $i => $l) { if ($l['parent'] !== null) { $enfants[$l['parent']][] = $i; } }
    $etendue = function (int $i) use (&$etendue, $enfants, $propre): ?array {
        $res = $propre[$i];
        foreach ($enfants[$i] ?? [] as $e) {
            $p = $etendue($e);
            if ($p) { $res = $res ? [min($res[0], $p[0]), max($res[1], $p[1])] : $p; }
        }
        return $res;
    };
    $out = [];
    foreach ($lignes as $i => $l) {
        if ($propre[$i]) { $out[$i] = ['debut' => $propre[$i][0], 'fin' => $propre[$i][1], 'source' => 'coloree']; continue; }
        // parent le plus proche qui a une période colorée
        $p = $l['parent']; $herite = null;
        while ($p !== null) { if ($propre[$p]) { $herite = $propre[$p]; break; } $p = $lignes[$p]['parent']; }
        if ($herite) { $out[$i] = ['debut' => $herite[0], 'fin' => $herite[1], 'source' => 'parent']; continue; }
        $e = $etendue($i);
        if ($e) { $out[$i] = ['debut' => $e[0], 'fin' => $e[1], 'source' => 'enfants']; continue; }
        $out[$i] = ['debut' => 1, 'fin' => PTA_NB_SEMAINES_IMPORT, 'source' => 'annee'];
    }
    return $out;
}
const PTA_NB_SEMAINES_IMPORT = 48;

/** Libellé lisible de l'origine d'une période. */
function ptaSourcePeriodeLabel(string $source): string
{
    return ['coloree' => 'semaines colorées', 'parent' => 'période du parent', 'enfants' => 'déduite des éléments rattachés',
            'annee' => 'toute l\'année (aucune période indiquée)'][$source] ?? $source;
}

/** Service (id) le plus proche d'un titre de bloc : nom exact, sinon nom contenu, sinon une lettre d'écart (SDARR / SDAAR). */
function ptaDeptDevine(string $titre, array $departements): ?int
{
    $t = ptaNormaliser($titre);
    if ($t === '') return null;
    foreach ($departements as $d) { if (ptaNormaliser($d['Nom_court']) === $t) return (int)$d['id_departement']; }
    foreach ($departements as $d) {
        $n = ptaNormaliser($d['Nom_court']);
        if ($n !== '' && (str_contains($t, $n) || ptaNormaliser($d['Libelle']) === $t)) return (int)$d['id_departement'];
    }
    $best = null; $bestD = 99;
    foreach ($departements as $d) {
        $n = str_replace(' ', '', ptaNormaliser($d['Nom_court'])); $tt = str_replace(' ', '', $t);
        if (strlen($n) >= 4 && strlen($tt) >= 4) { $dist = levenshtein($n, $tt); if ($dist < $bestD) { $bestD = $dist; $best = (int)$d['id_departement']; } }
    }
    return $bestD <= 1 ? $best : null;
}

/** Agent dont le nom figure dans le texte de la colonne RESPONSABLE (ex. « KASSI SERGE WARENN AMON (SDPS) »), si un seul correspond. */
function ptaAgentDepuisTexte(string $texte, array $agents): ?int
{
    $t = ' ' . ptaNormaliser(preg_replace('/\([^)]*\)/u', ' ', $texte)) . ' ';
    if (trim($t) === '') return null;
    $trouves = [];
    foreach ($agents as $a) {
        foreach ([ptaNormaliser($a['Prenom'] . ' ' . $a['Nom']), ptaNormaliser($a['Nom'] . ' ' . $a['Prenom'])] as $nom) {
            if ($nom !== '' && str_contains($t, ' ' . $nom . ' ')) { $trouves[(int)$a['ID_agent_dpip']] = true; }
        }
    }
    return count($trouves) === 1 ? (int)array_key_first($trouves) : null;
}

/** Besoins au format de l'application (« * Humain : libellé (1 500 000 FCFA) ») : [type, libellé, montant]. Texte libre : []. */
function ptaBesoinsApplication(string $texte): array
{
    $res = [];
    $types = ['MATERIEL' => 'materiel', 'HUMAIN' => 'humain', 'FINANCIER' => 'financier'];
    foreach (preg_split('/\R/u', $texte) as $ligne) {
        if (preg_match('/^\s*\*\s*(Mat[ée]riel|Humain|Financier)\s*:\s*(.+?)\s*(?:\(\s*([\d\s\x{00A0}]+)\s*FCFA\s*\))?\s*$/iu', $ligne, $m)) {
            $res[] = [$types[ptaNormaliser($m[1])] ?? 'materiel', mb_substr(trim($m[2]), 0, 150), (int)preg_replace('/\D+/', '', (string)($m[3] ?? ''))];
        } else {
            return [];   // une ligne au format libre : on garde tout le texte tel quel
        }
    }
    return $res;
}

/**
 * IMPORTE les activités analysées (tout ou rien : en cas d'erreur, rien n'est créé).
 * $choix[i] = ['ignorer' => bool, 'dept' => id, 'resp' => id, 'agent' => id|null] pour le bloc i.
 * @return array{blocs: list<array>, creees:int, ignorees:int}
 */
function ptaImporter(PDO $pdo, array $analyse, array $choix, int $annee, bool $coloreesTerminees, array $agentsEligibles, int $acteurId): array
{
    $bilan = ['blocs' => [], 'creees' => 0, 'ignorees' => 0];
    $pdo->beginTransaction();
    try {
        foreach ($analyse['blocs'] as $bi => $bloc) {
            $c = $choix[$bi] ?? ['ignorer' => true];
            $stat = ['nom' => $bloc['nom'], 'creees' => 0, 'doublons' => 0, 'ignore' => !empty($c['ignorer']), 'doublonsListe' => [], 'dept' => $c['dept'] ?? null, 'resp' => $c['resp'] ?? null, 'periodesParDefaut' => 0];
            if (!empty($c['ignorer'])) { $bilan['blocs'][] = $stat; continue; }
            $periodes = ptaPeriodes($bloc['lignes']);
            $ids = [];
            foreach ($bloc['lignes'] as $i => $l) {
                $agent = ptaAgentDepuisTexte($l['responsable'], $agentsEligibles) ?? ($c['agent'] ?? null);
                if (!$agent) { throw new RuntimeException("Ligne {$l['ligne']} : aucun agent n'a pu être déterminé (choisissez un agent par défaut pour le bloc « {$bloc['nom']} »)."); }
                $p = $periodes[$i];
                $debut = ptaSemaineDates($p['debut'], $annee)[0];
                $fin = ptaSemaineDates($p['fin'], $annee)[1];
                if ($p['source'] === 'annee') { $stat['periodesParDefaut']++; }

                $libelle = $l['libelle'] !== '' ? $l['libelle'] : '(sans libellé)';
                $obs = [];
                if (mb_strlen($libelle) > 150) { $obs[] = 'Libellé complet : ' . $libelle; $libelle = mb_substr($libelle, 0, 149) . '…'; }
                if ($l['observation'] !== '') { $obs[] = $l['observation']; }
                $produit = $l['livrables'];
                if (mb_strlen($produit) > 150) { $obs[] = 'Livrables : ' . $produit; $produit = mb_substr($produit, 0, 149) . '…'; }
                $besoins = ptaBesoinsApplication($l['besoins']);
                if ($l['besoins'] !== '' && !$besoins) { $obs[] = 'Besoins : ' . $l['besoins']; }
                $observation = $obs ? implode("\n", $obs) : null;
                $statut = ($coloreesTerminees && $l['semaines']) ? 'termine' : 'a_faire';
                $pere = $l['parent'] !== null ? ($ids[$l['parent']] ?? null) : null;

                // anti-doublon : même libellé, même type, même parent, même service
                $q = $pdo->prepare('SELECT ID__activites FROM activites WHERE Libelle = ? AND Types = ? AND pere_ID <=> ? AND ID_departement <=> ? LIMIT 1');
                $q->execute([$libelle, $l['type'], $pere, (int)$c['dept']]);
                $existant = $q->fetchColumn();
                if ($existant) {
                    $ids[$i] = (int)$existant; $stat['doublons']++; $bilan['ignorees']++;
                    if (count($stat['doublonsListe']) < 15) { $stat['doublonsListe'][] = ['ligne' => $l['ligne'], 'libelle' => $libelle, 'id' => (int)$existant]; }
                    continue;
                }
                $pdo->prepare(
                    'INSERT INTO activites (Libelle, Observation, Types, Produit, pere_ID, Date_debut, Date_fin, ID_agent_dpip, ID_responsable, ID_departement, Priorite, Statut)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([$libelle, $observation, $l['type'], $produit !== '' ? $produit : null, $pere, $debut, $fin, $agent, (int)$c['resp'], (int)$c['dept'], 'moyenne', $statut]);
                $id = (int)$pdo->lastInsertId();
                $ids[$i] = $id;
                foreach ($besoins as [$type, $lib, $montant]) {
                    $pdo->prepare('INSERT INTO besions (libelle, Type, Estimation_financiere, ID_activites) VALUES (?,?,?,?)')->execute([$lib, $type, min($montant, 999999999999), $id]);
                }
                majProgression($pdo, $id);
                logJournal($pdo, $id, $acteurId, 'creation', typeActiviteLabel($l['type']) . ' créée par import d\'un fichier PTA (administration)');
                $stat['creees']++; $bilan['creees']++;
            }
            $bilan['blocs'][] = $stat;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return $bilan;
}
