<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/pta_export.php';
$user = requireRole(['admin']);
$pageTitle = 'Exporter les activités vers Excel';

$xlsxDisponible = extension_loaded('zip');
$annees = ptaAnneesDisponibles($pdo);
$departements = $pdo->query('SELECT id_departement, Nom_court, Libelle, Types FROM departement ORDER BY id_departement')->fetchAll();
$idsDept = array_map('intval', array_column($departements, 'id_departement'));

$annee = (int)get('annee', (string)date('Y'));
if (!in_array($annee, $annees, true)) {
    $annee = (int)date('Y');
}

// --- Téléchargement (POST + jeton CSRF : c'est une action, même si elle ne modifie rien) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $anneePost = (int)post('annee');
    $portee = post('portee', 'tous');
    $deptId = ($portee !== 'tous' && in_array((int)$portee, $idsDept, true)) ? (int)$portee : null;
    $colorerTaches = false;   // seules les périodes des activités et sous-activités sont colorées, comme dans le PTA
    if (!$xlsxDisponible) {
        flash('danger', "Le format .xlsx n'est pas disponible sur ce serveur (extension PHP « zip » désactivée) : l'export Excel est impossible ici.");
        redirect('/admin/activites_export.php');
    }
    if (!in_array($anneePost, $annees, true)) {
        flash('danger', 'Année invalide.');
        redirect('/admin/activites_export.php');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'pta_export_') . '.xlsx';
    try {
        $res = ptaGenererClasseur($pdo, $tmp, $anneePost, $deptId, $colorerTaches);
    } catch (Throwable $e) {
        @unlink($tmp);
        flash('danger', 'Export impossible : ' . $e->getMessage());
        redirect('/admin/activites_export.php');
    }
    logModif($pdo, 'activites', null, 'export', 'Export Excel PTA ' . $anneePost . ' — ' . ($deptId === null ? 'tous les services' : $res['feuille']) . ' (' . $res['nbActivites'] . ' activité(s))');
    $nomFichier = $res['feuille'] . '_' . date('Ymd') . '.xlsx';
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nomFichier . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

include __DIR__ . '/../includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-9">
        <div class="card mb-3">
            <div class="card-header">Exporter les activités vers un fichier Excel (format PTA)</div>
            <div class="card-body">
                <p>Génère le <strong>plan de travail annuel</strong> à partir des activités saisies dans l'application — par tous les responsables, pour toutes les directions et tous les services —, <strong>avec la même présentation que le fichier PTA chargé</strong> (« PTA-<?= (int)$annee ?>-DPIP ») :</p>
                <ul class="small">
                    <li>un bloc par direction / service, avec ses rubriques <em>ACTIVITÉS COURANTES</em> et <em>ACTIVITÉS INNOVANTES</em> ;</li>
                    <li>activités, sous-activités et tâches numérotées (<code>Activité 1:</code>, <code>Sous-activité 1.1:</code>, <code>Tache 1.1.1 :</code>) ;</li>
                    <li>diagramme de Gantt : 4 semaines par mois, semaines colorées en bleu d'après les dates de début et de fin des activités et sous-activités ;</li>
                    <li>fichier livré sans filtre automatique ;</li>
                    <li>colonnes <strong>RESPONSABLE</strong> (agent en charge et son service), <strong>OBSERVATIONS</strong>, <strong>LIVRABLES</strong> (produit attendu et livrables déposés) et <strong>BESOINS</strong>.</li>
                </ul>
                <?php if (!$xlsxDisponible): ?>
                    <div class="alert alert-warning small">L'extension PHP « zip » n'est pas activée sur ce serveur : l'export Excel est indisponible ici.</div>
                <?php endif; ?>

                <form method="post" class="row g-3 align-items-end">
                    <?= csrfField() ?>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Année</label>
                        <select name="annee" id="annee" class="form-select" onchange="location.href='?annee='+this.value">
                            <?php foreach ($annees as $a): ?>
                                <option value="<?= $a ?>" <?= $a === $annee ? 'selected' : '' ?>><?= $a ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Périmètre</label>
                        <select name="portee" class="form-select">
                            <option value="tous">Toutes les directions et tous les services (une feuille)</option>
                            <?php foreach ($departements as $d): ?>
                                <option value="<?= (int)$d['id_departement'] ?>"><?= h($d['Nom_court'] . ' — ' . $d['Libelle']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary w-100" <?= $xlsxDisponible ? '' : 'disabled' ?>><i class="bi bi-file-earmark-excel"></i> Exporter</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="mt-3">
            <a href="<?= BASE_URL ?>/admin/activites.php" class="btn btn-light border">Retour aux activités</a>
            <a href="<?= BASE_URL ?>/admin/activites_import.php" class="btn btn-outline-secondary ms-1"><i class="bi bi-file-earmark-arrow-up"></i> Importer depuis Excel</a>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
