<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/xlsx.php';
$user = requireRole(['admin']);
$pageTitle = 'Importer des activités depuis Excel';

// Colonnes attendues, dans cet ordre exact (la 1ère ligne du fichier — les
// titres — est toujours ignorée : seul l'ORDRE des colonnes compte).
const IMPORT_ENTETES = [
    'Repère', 'Type', 'Rattaché au repère', 'Libellé', 'Observation', 'Produit attendu',
    'Matricule responsable', 'Matricule agent', 'Date début', 'Date fin', 'Priorité', 'Statut',
];
const IMPORT_MAX_LIGNES = 2000;

$typeParLibelle = [
    'activite' => 'activite', 'activité' => 'activite',
    'sous-activite' => 'sous-activite', 'sous-activité' => 'sous-activite', 'sous activite' => 'sous-activite', 'sous activité' => 'sous-activite',
    'tache' => 'tache', 'tâche' => 'tache',
];
$statutsValides = ['a_faire', 'en_cours', 'en_attente', 'termine', 'en_retard', 'annule'];

$xlsxDisponible = extension_loaded('zip');

const IMPORT_EXEMPLE = [
    ['1', 'Activité', '', 'Sensibilisation des directions', '', 'Rapport de sensibilisation', 'RESP001', 'AGT001', '2026-01-05', '2026-03-31', 'haute', ''],
    ['2', 'Tâche', '1', 'Préparer le support de présentation', '', '', 'RESP001', 'AGT001', '2026-01-05', '2026-01-20', 'moyenne', ''],
];

/** Modèle CSV à télécharger — toujours disponible, sans dépendance particulière. */
if (get('modele') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="modele_import_activites.csv"');
    echo "\xEF\xBB\xBF"; // BOM UTF-8 : Excel affiche correctement les accents
    $out = fopen('php://output', 'w');
    fputcsv($out, IMPORT_ENTETES, ';');
    foreach (IMPORT_EXEMPLE as $ligne) {
        fputcsv($out, $ligne, ';');
    }
    fclose($out);
    exit;
}

/** Modèle .xlsx à télécharger — seulement si l'extension PHP "zip" est activée côté serveur. */
if (get('modele') === 'xlsx') {
    if (!$xlsxDisponible) {
        flash('danger', "Le format .xlsx n'est pas disponible sur ce serveur (extension PHP « zip » désactivée) : utilisez le modèle CSV, qui s'ouvre aussi très bien dans Excel.");
        redirect('/admin/activites_import.php');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'pta_modele_') . '.xlsx';
    xlsxEcrireFeuille($tmp, IMPORT_ENTETES, IMPORT_EXEMPLE);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="modele_import_activites.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

function importParseDate(string $valeur): ?string
{
    $valeur = trim($valeur);
    if ($valeur === '') return null;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valeur, $m)) {
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $valeur : null;
    }
    if (preg_match('#^(\d{1,2})[/\-](\d{1,2})[/\-](\d{4})$#', $valeur, $m)) {
        return checkdate((int)$m[2], (int)$m[1], (int)$m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
    }
    if (preg_match('/^\d+(\.\d+)?$/', $valeur) && (float)$valeur > 20000) {
        return xlsxSerieVersDate((float)$valeur);
    }
    return null;
}

/** Lit un fichier .csv (virgule ou point-virgule) en tableau de lignes de chaînes. */
function importLireCsv(string $chemin): array
{
    $contenu = file_get_contents($chemin);
    if ($contenu === false) throw new RuntimeException('Fichier illisible.');
    if (substr($contenu, 0, 3) === "\xEF\xBB\xBF") $contenu = substr($contenu, 3);
    $premiereLigne = strtok($contenu, "\n");
    $sep = substr_count($premiereLigne, ';') >= substr_count($premiereLigne, ',') ? ';' : ',';
    $lignes = [];
    $f = fopen('php://temp', 'r+');
    fwrite($f, $contenu);
    rewind($f);
    while (($row = fgetcsv($f, 0, $sep)) !== false) {
        $lignes[] = array_map('trim', $row);
    }
    fclose($f);
    return $lignes;
}

$resultats = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    if (empty($_FILES['fichier']) || $_FILES['fichier']['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Veuillez choisir un fichier .xlsx ou .csv à importer.';
    } elseif ($_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "Erreur lors du téléversement du fichier (code {$_FILES['fichier']['error']}).";
    } else {
        $nomOriginal = $_FILES['fichier']['name'];
        $ext = strtolower(pathinfo($nomOriginal, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'csv'], true)) {
            $errors[] = 'Formats acceptés : .xlsx (Excel) ou .csv uniquement.';
        } elseif ($ext === 'xlsx' && !$xlsxDisponible) {
            $errors[] = "Le format .xlsx n'est pas pris en charge sur ce serveur (extension PHP « zip » désactivée). Enregistrez votre fichier au format CSV depuis Excel (Fichier → Enregistrer sous → CSV) puis réimportez-le.";
        } elseif ($_FILES['fichier']['size'] > MAX_UPLOAD_SIZE) {
            $errors[] = 'Le fichier dépasse la taille maximale autorisée (10 Mo).';
        } else {
            try {
                $lignesBrutes = $ext === 'xlsx'
                    ? xlsxLireFeuille($_FILES['fichier']['tmp_name'])
                    : importLireCsv($_FILES['fichier']['tmp_name']);
                array_shift($lignesBrutes); // ligne de titres

                if (count($lignesBrutes) > IMPORT_MAX_LIGNES) {
                    $errors[] = 'Le fichier contient plus de ' . IMPORT_MAX_LIGNES . ' lignes de données ; scindez-le en plusieurs imports.';
                } else {
                    $succes = [];
                    $echecs = [];
                    $doublons = [];
                    $reperesInseres = []; // repère (texte du fichier) => ID__activites créé ou déjà existant

                    foreach ($lignesBrutes as $i => $ligne) {
                        $numLigneFichier = $i + 2; // +1 (0-based) +1 (ligne de titres)
                        $ligne = array_map(fn($v) => trim((string)$v), $ligne);
                        if (implode('', $ligne) === '') continue; // ligne vide, ignorée silencieusement

                        [$repere, $typeBrut, $rattacheA, $libelle, $observation, $produit,
                            $matResp, $matAgent, $dateDebutBrut, $dateFinBrut, $priorite, $statutBrut] = array_pad($ligne, 12, '');

                        try {
                            // Le repère n'a besoin d'être unique que localement : s'il est réutilisé
                            // (ex. on renumérote 1, 2, 3... pour chaque nouvelle activité), la ligne la
                            // plus récente l'emporte pour les rattachements qui la référencent ensuite.
                            // Une ligne sans repère est acceptée : elle ne pourra simplement pas servir
                            // de parent à une ligne suivante (cas normal d'une tâche terminale).
                            $type = $typeParLibelle[mb_strtolower(trim($typeBrut))] ?? null;
                            if ($type === null) throw new RuntimeException("Type invalide : « $typeBrut » (attendu : Activité, Sous-activité ou Tâche).");

                            $pereId = null;
                            if ($type !== 'activite') {
                                if ($rattacheA === '') throw new RuntimeException('Une sous-activité ou une tâche doit indiquer le repère de son activité/sous-activité parente.');
                                if (!isset($reperesInseres[$rattacheA])) throw new RuntimeException("Repère parent « $rattacheA » introuvable : il doit correspondre à une ligne précédente du fichier.");
                                $pereId = $reperesInseres[$rattacheA]['id'];
                                $typeParent = $reperesInseres[$rattacheA]['type'];
                                if ($type === 'sous-activite' && $typeParent !== 'activite') throw new RuntimeException('Une sous-activité doit être rattachée à une activité (pas à une tâche).');
                                if ($type === 'tache' && !in_array($typeParent, ['activite', 'sous-activite'], true)) throw new RuntimeException('Une tâche doit être rattachée à une activité ou une sous-activité.');
                            }

                            if ($libelle === '') throw new RuntimeException('Le libellé est obligatoire.');

                            $stR = $pdo->prepare("SELECT * FROM agent_dpip WHERE Matricule = ? AND " . sqlEstRespService() . " AND statut_compte = 'actif'");
                            $stR->execute([$matResp]);
                            $respInfo = $stR->fetch();
                            if (!$respInfo) throw new RuntimeException("Responsable introuvable ou invalide (matricule « $matResp »).");

                            $stA = $pdo->prepare("SELECT * FROM agent_dpip WHERE Matricule = ? AND " . sqlEstAgent() . " AND statut_compte = 'actif'");
                            $stA->execute([$matAgent]);
                            $agentInfo = $stA->fetch();
                            if (!$agentInfo) throw new RuntimeException("Agent introuvable ou invalide (matricule « $matAgent »).");

                            $dateDebut = importParseDate($dateDebutBrut);
                            $dateFin = importParseDate($dateFinBrut);
                            if (!$dateDebut || !$dateFin) throw new RuntimeException('Dates de début/fin manquantes ou invalides (format attendu AAAA-MM-JJ ou JJ/MM/AAAA).');
                            if (strtotime($dateFin) < strtotime($dateDebut)) throw new RuntimeException('La date de fin doit être postérieure à la date de début.');

                            $priorite = mb_substr(trim($priorite), 0, 50) ?: 'moyenne';

                            $statut = 'a_faire';
                            if (trim($statutBrut) !== '') {
                                if (!in_array(trim($statutBrut), $statutsValides, true)) {
                                    throw new RuntimeException("Statut invalide : « $statutBrut » (valeurs possibles : " . implode(', ', $statutsValides) . ').');
                                }
                                $statut = trim($statutBrut);
                            }

                            $deptId = $agentInfo['ID_departement'] ?: null;

                            // Anti-doublon : une activité de même libellé, même type et même parent
                            // est considérée comme déjà importée — même si l'agent, le responsable
                            // ou les dates diffèrent — et n'est donc pas recréée. On réutilise celle
                            // qui existe déjà pour les rattachements des lignes suivantes.
                            $stDoublon = $pdo->prepare(
                                'SELECT ID__activites FROM activites
                                 WHERE Libelle = ? AND Types = ? AND ' . ($pereId === null ? 'pere_ID IS NULL' : 'pere_ID = ?') . '
                                 LIMIT 1'
                            );
                            $paramsDoublon = [$libelle, $type];
                            if ($pereId !== null) $paramsDoublon[] = $pereId;
                            $stDoublon->execute($paramsDoublon);
                            $existantId = $stDoublon->fetchColumn();

                            if ($existantId) {
                                if ($repere !== '') {
                                    $reperesInseres[$repere] = ['id' => (int)$existantId, 'type' => $type];
                                }
                                $doublons[] = ['ligne' => $numLigneFichier, 'libelle' => $libelle, 'id' => (int)$existantId];
                                continue;
                            }

                            $pdo->prepare(
                                'INSERT INTO activites (Libelle, Observation, Types, Produit, pere_ID, Date_debut, Date_fin, ID_agent_dpip, ID_responsable, ID_departement, Priorite, Statut)
                                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
                            )->execute([
                                $libelle, $observation ?: null, $type, $produit ?: null, $pereId,
                                $dateDebut, $dateFin, $agentInfo['ID_agent_dpip'], $respInfo['ID_agent_dpip'], $deptId, $priorite, $statut,
                            ]);
                            $newId = (int)$pdo->lastInsertId();
                            if ($repere !== '') {
                                $reperesInseres[$repere] = ['id' => $newId, 'type' => $type];
                            }

                            majProgression($pdo, $newId);
                            logJournal($pdo, $newId, (int)$user['ID_agent_dpip'], 'creation', typeActiviteLabel($type) . ' créée par import Excel (admin)');
                            createNotification($pdo, (int)$agentInfo['ID_agent_dpip'], 'Nouvelle attribution', 'Vous avez une nouvelle ' . mb_strtolower(typeActiviteLabel($type)) . ' : "' . $libelle . '".', '/tache_detail.php?id=' . $newId);
                            createNotification($pdo, (int)$respInfo['ID_agent_dpip'], 'Nouvelle activité dans votre périmètre', 'Une ' . mb_strtolower(typeActiviteLabel($type)) . ' "' . $libelle . '" a été importée par l\'administrateur.', '/tache_detail.php?id=' . $newId);
                            if (trim($observation) !== '') {
                                notifierObservationActivite($pdo, $newId, (int)$agentInfo['ID_agent_dpip'], (int)$user['ID_agent_dpip'], $user['Prenom'] . ' ' . $user['Nom'], $libelle);
                            }
                            mailNouvelleTache($agentInfo, ['Libelle' => $libelle, 'Date_fin' => $dateFin, 'Types' => $type]);

                            $succes[] = ['ligne' => $numLigneFichier, 'libelle' => $libelle, 'id' => $newId];
                        } catch (Throwable $e) {
                            $echecs[] = ['ligne' => $numLigneFichier, 'repere' => $repere, 'message' => $e->getMessage()];
                        }
                    }

                    $resultats = ['succes' => $succes, 'echecs' => $echecs, 'doublons' => $doublons, 'total' => count($lignesBrutes)];
                }
            } catch (Throwable $e) {
                $errors[] = 'Impossible de lire le fichier : ' . $e->getMessage();
            }
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-9">
        <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endforeach; ?>

        <?php if ($resultats): ?>
            <div class="card mb-3">
                <div class="card-header">Résultat de l'import</div>
                <div class="card-body">
                    <p class="mb-2"><span class="badge bg-success"><?= count($resultats['succes']) ?> créée(s)</span>
                        <span class="badge bg-secondary ms-1"><?= count($resultats['doublons']) ?> ignorée(s) (déjà importée(s))</span>
                        <span class="badge bg-danger ms-1"><?= count($resultats['echecs']) ?> en erreur</span>
                        <span class="text-muted ms-1">sur <?= $resultats['total'] ?> ligne(s) de données lues</span></p>

                    <?php if ($resultats['doublons']): ?>
                        <div class="table-responsive mt-3">
                            <table class="table table-sm">
                                <thead><tr><th>Ligne (fichier)</th><th>Libellé</th><th></th></tr></thead>
                                <tbody>
                                <?php foreach ($resultats['doublons'] as $d): ?>
                                    <tr class="table-secondary">
                                        <td><?= (int)$d['ligne'] ?></td>
                                        <td><?= h($d['libelle']) ?></td>
                                        <td><a href="<?= BASE_URL ?>/tache_detail.php?id=<?= (int)$d['id'] ?>" target="_blank">Voir l'existante</a></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="form-text">Ces lignes correspondent à des activités déjà présentes (même libellé, même type et même rattachement) : elles n'ont pas été recréées, même si l'agent, le responsable ou les dates différaient.</div>
                    <?php endif; ?>

                    <?php if ($resultats['echecs']): ?>
                        <div class="table-responsive mt-3">
                            <table class="table table-sm">
                                <thead><tr><th>Ligne (fichier)</th><th>Repère</th><th>Erreur</th></tr></thead>
                                <tbody>
                                <?php foreach ($resultats['echecs'] as $e): ?>
                                    <tr class="table-danger">
                                        <td><?= (int)$e['ligne'] ?></td>
                                        <td><?= h($e['repere']) ?></td>
                                        <td><?= h($e['message']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="form-text">Corrigez ces lignes dans votre fichier (ou dans un nouveau fichier ne contenant que les lignes en erreur) puis réimportez-les.</div>
                    <?php endif; ?>

                    <div class="mt-3 d-flex gap-2">
                        <a href="<?= BASE_URL ?>/admin/activites.php" class="btn btn-primary">Voir les activités</a>
                        <a href="<?= BASE_URL ?>/admin/activites_import.php" class="btn btn-light border">Importer un autre fichier</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">Importer des activités / tâches depuis un fichier Excel</div>
            <div class="card-body">
                <p>Permet de créer en une fois un grand nombre d'activités, sous-activités et tâches, sans les saisir une à une.</p>

                <ol class="small">
                    <li>Téléchargez le modèle ci-dessous et remplissez-le (une ligne par activité / sous-activité / tâche).</li>
                    <li>La 1<sup>re</sup> ligne (titres) est ignorée : seul l'<strong>ordre des colonnes</strong> compte, ne changez pas l'ordre du modèle.</li>
                    <li><strong>Repère</strong> : un numéro ou code que vous inventez, à renseigner uniquement sur les lignes qui auront des enfants (une activité ou une sous-activité), pour que ses sous-activités / tâches puissent la référencer via la colonne <strong>Rattaché au repère</strong>. Vous pouvez recommencer à 1, 2, 3… pour chaque nouvelle activité (pas besoin d'un numéro unique dans tout le fichier).</li>
                    <li>Une activité/sous-activité <strong>parente doit toujours être placée sur une ligne au-dessus</strong> de ses enfants dans le fichier.</li>
                    <li><strong>Matricule responsable</strong> et <strong>Matricule agent</strong> doivent correspondre à des comptes actifs existants.</li>
                    <li>Dates au format <code>AAAA-MM-JJ</code> ou <code>JJ/MM/AAAA</code>.</li>
                    <li>Priorité et Statut sont facultatifs (valeurs par défaut : « moyenne » et « À faire »).</li>
                    <li><strong>Anti-doublon</strong> : si une activité de même libellé, même type et même rattachement existe déjà, elle n'est pas recréée — <strong>même si l'agent, le responsable ou les dates diffèrent</strong>. Deux lignes qui décrivent la même activité pour deux agents différents doivent donc porter des libellés distincts (ex. « Formation — volet A » / « Formation — volet B ») si vous voulez vraiment deux entrées.</li>
                </ol>

                <div class="mb-4 d-flex gap-2 flex-wrap">
                    <a href="<?= BASE_URL ?>/admin/activites_import.php?modele=csv" class="btn btn-outline-secondary">
                        <i class="bi bi-download"></i> Télécharger le modèle (CSV, s'ouvre dans Excel)
                    </a>
                    <?php if ($xlsxDisponible): ?>
                        <a href="<?= BASE_URL ?>/admin/activites_import.php?modele=xlsx" class="btn btn-outline-secondary">
                            <i class="bi bi-download"></i> Télécharger le modèle (.xlsx)
                        </a>
                    <?php endif; ?>
                </div>
                <?php if (!$xlsxDisponible): ?>
                    <div class="alert alert-info small">L'extension PHP « zip » n'est pas activée sur ce serveur : le modèle et l'import se font en CSV (ouvrez-le, remplissez-le et enregistrez-le tel quel depuis Excel — inutile de le convertir en .xlsx).</div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <label class="form-label small fw-semibold">Fichier à importer (.xlsx ou .csv)</label>
                    <input type="file" name="fichier" class="form-control" accept=".xlsx,.csv" required>
                    <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-upload"></i> Importer</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
