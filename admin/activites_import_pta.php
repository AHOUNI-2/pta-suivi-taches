<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/pta_import.php';
$user = requireRole(['admin']);
$pageTitle = 'Importer un fichier PTA';

$departements = $pdo->query('SELECT id_departement, Nom_court, Libelle FROM departement ORDER BY Nom_court')->fetchAll();
$responsables = $pdo->query("SELECT ID_agent_dpip, Prenom, Nom, Matricule FROM agent_dpip ag WHERE " . sqlEstRespService('ag.') . " AND statut_compte = 'actif' ORDER BY Nom, Prenom")->fetchAll();
$agents = $pdo->query("SELECT ID_agent_dpip, Prenom, Nom, Matricule FROM agent_dpip ag WHERE " . sqlEstAgent('ag.') . " AND statut_compte = 'actif' ORDER BY Nom, Prenom")->fetchAll();
$idsDept = array_map('intval', array_column($departements, 'id_departement'));
$idsResp = array_map('intval', array_column($responsables, 'ID_agent_dpip'));
$idsAgent = array_map('intval', array_column($agents, 'ID_agent_dpip'));

$errors = [];
$bilan = null;
$etape = 'fichier';
$annee = (int)date('Y');

if (get('annuler') === '1') {
    unset($_SESSION['pta_import']);
    redirect('/admin/activites_import_pta.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = post('action');
    if ($action === 'analyser') {
        $f = $_FILES['fichier'] ?? null;
        if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
            $errors[] = 'Veuillez choisir un fichier PTA (.xlsx).';
        } elseif ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Erreur lors du téléversement du fichier (code {$f['error']}).";
        } elseif (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
            $errors[] = 'Seul le format .xlsx (Excel) est accepté.';
        } elseif ($f['size'] > MAX_UPLOAD_SIZE) {
            $errors[] = 'Le fichier dépasse la taille maximale autorisée (10 Mo).';
        } else {
            try {
                $analyse = ptaLireClasseur($f['tmp_name']);
                $_SESSION['pta_import'] = ['analyse' => $analyse, 'nom' => basename($f['name'])];
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
    } elseif ($action === 'importer' && !empty($_SESSION['pta_import'])) {
        $analyse = $_SESSION['pta_import']['analyse'];
        $annee = (int)post('annee');
        if ($annee < 2000 || $annee > 2100) {
            $errors[] = "L'année du PTA est invalide.";
        }
        $choix = [];
        $nbActifs = 0;
        foreach ($analyse['blocs'] as $i => $b) {
            if (empty($_POST['bloc'][$i]['importer'])) { $choix[$i] = ['ignorer' => true]; continue; }
            $c = $_POST['bloc'][$i];
            $dept = (int)($c['dept'] ?? 0); $resp = (int)($c['resp'] ?? 0); $ag = (int)($c['agent'] ?? 0);
            if (!in_array($dept, $idsDept, true)) { $errors[] = "Bloc « {$b['nom']} » : choisissez la direction / le service."; }
            if (!in_array($resp, $idsResp, true)) { $errors[] = "Bloc « {$b['nom']} » : choisissez le responsable."; }
            if ($ag && !in_array($ag, $idsAgent, true)) { $errors[] = "Bloc « {$b['nom']} » : agent par défaut invalide."; }
            $choix[$i] = ['ignorer' => false, 'dept' => $dept, 'resp' => $resp, 'agent' => $ag ?: null];
            $nbActifs++;
        }
        if ($nbActifs === 0 && !$errors) { $errors[] = 'Cochez au moins un bloc à importer.'; }
        if (!$errors) {
            try {
                $bilan = ptaImporter($pdo, $analyse, $choix, $annee, post('terminees') === '1', $agents, (int)$user['ID_agent_dpip']);
                logModif($pdo, 'activites', null, 'import', 'Import PTA ' . $annee . ' (' . $_SESSION['pta_import']['nom'] . ') : ' . $bilan['creees'] . ' créée(s), ' . $bilan['ignorees'] . ' déjà existante(s)');
                foreach ($bilan['blocs'] as $s) {
                    if (!$s['ignore'] && $s['creees'] > 0 && $s['resp']) {
                        createNotification($pdo, (int)$s['resp'], 'Activités importées', $s['creees'] . ' activité(s) du PTA « ' . $s['nom'] . ' » ont été importées par l\'administrateur.', '/responsable/activites.php');
                    }
                }
                unset($_SESSION['pta_import']);
            } catch (Throwable $e) {
                $errors[] = 'Import annulé, rien n\'a été créé : ' . $e->getMessage();
            }
        }
    }
}

$session = $_SESSION['pta_import'] ?? null;
if ($bilan) { $etape = 'bilan'; }
elseif ($session) { $etape = 'apercu'; $analyse = $session['analyse']; $annee = $analyse['annee'] ?? $annee; if (isset($_POST['annee']) && $errors) { $annee = (int)$_POST['annee']; } }

include __DIR__ . '/../includes/header.php';
$optionsPersonnes = function (array $liste, ?int $sel) {
    foreach ($liste as $p) {
        echo '<option value="' . (int)$p['ID_agent_dpip'] . '"' . ($sel === (int)$p['ID_agent_dpip'] ? ' selected' : '') . '>' . h($p['Nom'] . ' ' . $p['Prenom'] . ' (' . $p['Matricule'] . ')') . '</option>';
    }
};
?>
<div class="row justify-content-center">
    <div class="col-lg-11">
        <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endforeach; ?>

        <?php if ($etape === 'bilan'): ?>
            <div class="card mb-3">
                <div class="card-header">Résultat de l'import du PTA</div>
                <div class="card-body">
                    <p><span class="badge bg-success"><?= (int)$bilan['creees'] ?> créée(s)</span>
                        <span class="badge bg-secondary ms-1"><?= (int)$bilan['ignorees'] ?> déjà existante(s), non recréée(s)</span></p>
                    <table class="table table-sm">
                        <thead><tr><th>Bloc</th><th>Créées</th><th>Déjà existantes</th><th>Remarque</th></tr></thead>
                        <tbody>
                        <?php foreach ($bilan['blocs'] as $s): ?>
                            <tr class="<?= $s['ignore'] ? 'table-secondary' : '' ?>">
                                <td><?= h($s['nom']) ?></td>
                                <td><?= $s['ignore'] ? '—' : (int)$s['creees'] ?></td>
                                <td><?= $s['ignore'] ? '—' : (int)$s['doublons'] ?></td>
                                <td class="small text-muted"><?= $s['ignore'] ? 'Bloc non importé' : ($s['periodesParDefaut'] ? (int)$s['periodesParDefaut'] . ' ligne(s) sans période : toute l\'année' : '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <a href="<?= BASE_URL ?>/admin/activites.php" class="btn btn-primary">Voir les activités</a>
                    <a href="<?= BASE_URL ?>/admin/activites_import_pta.php" class="btn btn-light border">Importer un autre fichier PTA</a>
                </div>
            </div>

        <?php elseif ($etape === 'apercu'): ?>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="importer">
                <div class="card mb-3">
                    <div class="card-header">Aperçu avant import — <?= h($session['nom']) ?> (feuille « <?= h($analyse['feuille']) ?> »)</div>
                    <div class="card-body">
                        <p class="mb-2"><strong><?= (int)$analyse['nbLignes'] ?></strong> activité(s), sous-activité(s) et tâche(s) reconnues dans <strong><?= count($analyse['blocs']) ?></strong> bloc(s). <strong>Rien n'est encore créé</strong> : vérifiez puis confirmez en bas de page.</p>
                        <div class="row g-3 mb-2">
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold">Année du PTA</label>
                                <input type="number" name="annee" class="form-control" min="2000" max="2100" value="<?= (int)$annee ?>" required>
                            </div>
                            <div class="col-md-9 d-flex align-items-end">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="terminees" value="1" id="terminees">
                                    <label class="form-check-label small" for="terminees">Les lignes dont les semaines sont colorées (en bleu) sont des éléments <strong>déjà terminés</strong> (statut « Terminé » — comme dans l'export). Sinon : statut « À faire ».</label>
                                </div>
                            </div>
                        </div>
                        <?php foreach ($analyse['avertissements'] as $w): ?><div class="alert alert-warning py-1 small mb-1"><?= h($w) ?></div><?php endforeach; ?>
                    </div>
                </div>

                <?php foreach ($analyse['blocs'] as $i => $bloc):
                    $periodes = ptaPeriodes($bloc['lignes']);
                    $dev = ptaDeptDevine($bloc['nom'], $departements);
                    $nbReconnus = 0; $nbSansAgent = 0;
                    foreach ($bloc['lignes'] as $l) { if (ptaAgentDepuisTexte($l['responsable'], $agents)) $nbReconnus++; }
                    $nbType = ['activite' => 0, 'sous-activite' => 0, 'tache' => 0];
                    foreach ($bloc['lignes'] as $l) { $nbType[$l['type']]++; }
                ?>
                    <div class="card mb-3">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span><strong><?= h($bloc['nom']) ?></strong>
                                <span class="text-muted small ms-2"><?= $nbType['activite'] ?> activité(s), <?= $nbType['sous-activite'] ?> sous-activité(s), <?= $nbType['tache'] ?> tâche(s)</span></span>
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" name="bloc[<?= $i ?>][importer]" value="1" id="b<?= $i ?>" <?= $bloc['lignes'] ? 'checked' : 'disabled' ?>>
                                <label class="form-check-label small" for="b<?= $i ?>">Importer ce bloc</label>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row g-3 mb-3">
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Direction / service de rattachement</label>
                                    <select name="bloc[<?= $i ?>][dept]" class="form-select">
                                        <option value="">— choisir —</option>
                                        <?php foreach ($departements as $d): ?>
                                            <option value="<?= (int)$d['id_departement'] ?>" <?= $dev === (int)$d['id_departement'] ? 'selected' : '' ?>><?= h($d['Nom_court'] . ' — ' . $d['Libelle']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ($dev): ?><div class="form-text">Proposé d'après le titre du bloc.</div><?php endif; ?>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Responsable Service / Sous-directeur</label>
                                    <select name="bloc[<?= $i ?>][resp]" class="form-select"><option value="">— choisir —</option><?php $optionsPersonnes($responsables, null); ?></select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Agent par défaut</label>
                                    <select name="bloc[<?= $i ?>][agent]" class="form-select"><option value="">— aucun —</option><?php $optionsPersonnes($agents, null); ?></select>
                                    <div class="form-text"><?= $nbReconnus ?> ligne(s) sur <?= count($bloc['lignes']) ?> ont un agent reconnu par son nom (colonne RESPONSABLE) ; l'agent par défaut sert pour les autres.</div>
                                </div>
                            </div>
                            <details>
                                <summary class="small">Voir les lignes de ce bloc</summary>
                                <div class="table-responsive mt-2" style="max-height:320px;overflow:auto">
                                    <table class="table table-sm small mb-0">
                                        <thead><tr><th>Ligne</th><th>Type</th><th>N°</th><th>Libellé</th><th>Période (semaines)</th><th>Responsable (fichier)</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($bloc['lignes'] as $k => $l): $p = $periodes[$k]; ?>
                                            <tr>
                                                <td><?= (int)$l['ligne'] ?></td>
                                                <td><?= h(typeActiviteLabel($l['type'])) ?></td>
                                                <td><?= h($l['numero']) ?></td>
                                                <td><?= h(mb_substr($l['libelle'], 0, 90)) ?></td>
                                                <td>S<?= (int)$p['debut'] ?> → S<?= (int)$p['fin'] ?> <span class="text-muted">(<?= h(ptaSourcePeriodeLabel($p['source'])) ?>)</span></td>
                                                <td><?= h($l['responsable']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </details>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="d-flex gap-2 mb-4">
                    <button type="submit" class="btn btn-primary" onclick="return confirm('Créer ces activités dans l\'application ?');"><i class="bi bi-check2-circle"></i> Confirmer l'import</button>
                    <a href="<?= BASE_URL ?>/admin/activites_import_pta.php?annuler=1" class="btn btn-light border">Annuler</a>
                </div>
            </form>

        <?php else: ?>
            <div class="card">
                <div class="card-header">Importer un fichier PTA (un par direction ou service)</div>
                <div class="card-body">
                    <p>Charge directement un fichier Excel <strong>au format PTA</strong> (le même que celui produit par « Exporter vers Excel » et que le PTA de la DPIP).</p>
                    <ol class="small">
                        <li>Le fichier est d'abord <strong>analysé</strong> : rien n'est créé tant que vous n'avez pas confirmé.</li>
                        <li>Les lignes « Activité n: », « Sous-activité n.m: » et « Tache n.m.k : » sont reconnues, avec leur rattachement, leurs semaines (période), observations, livrables et besoins.</li>
                        <li>Comme le PTA ne contient pas de matricules, vous choisissez ensuite, pour chaque bloc (SDPS, SDAAR, SERVICE IT…), la <strong>direction / le service</strong>, le <strong>responsable</strong> et l'<strong>agent par défaut</strong>. Si la colonne RESPONSABLE contient le nom d'un agent connu, il est utilisé.</li>
                        <li>Les périodes sont déduites des semaines colorées (4 semaines par mois) ; sans semaine colorée, la période du parent, sinon celle des éléments rattachés, sinon toute l'année.</li>
                        <li><strong>Anti-doublon</strong> : une activité de même libellé, type, rattachement et service n'est pas recréée.</li>
                        <li>L'import est <strong>tout ou rien</strong> : en cas d'erreur, rien n'est enregistré.</li>
                    </ol>
                    <form method="post" enctype="multipart/form-data">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="analyser">
                        <label class="form-label small fw-semibold">Fichier PTA (.xlsx)</label>
                        <input type="file" name="fichier" class="form-control" accept=".xlsx" required>
                        <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-search"></i> Analyser le fichier</button>
                        <a href="<?= BASE_URL ?>/admin/activites_import.php" class="btn btn-light border mt-3 ms-1">Import par matricules (modèle simple)</a>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
