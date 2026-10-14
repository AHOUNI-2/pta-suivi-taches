<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = requireRole(['admin']);
$pageTitle = 'Activités & tâches';
actualiserTachesEnRetard($pdo);

$filtreStatut = get('statut');
$filtreDept = get('departement');
$filtreResp = get('responsable');
$filtreType = get('type');
$recherche = get('q');

$sql = "SELECT a.*, ag.Nom AS ag_nom, ag.Prenom AS ag_prenom, r.Nom AS resp_nom, r.Prenom AS resp_prenom, d.Nom_court AS dept_nom, d.code_departement AS dept_code,
               p.Libelle AS pere_libelle, p.Types AS pere_type
        FROM activites a
        JOIN agent_dpip ag ON ag.ID_agent_dpip = a.ID_agent_dpip
        JOIN agent_dpip r ON r.ID_agent_dpip = a.ID_responsable
        LEFT JOIN departement d ON d.id_departement = a.ID_departement
        LEFT JOIN activites p ON p.ID__activites = a.pere_ID
        WHERE 1=1";
$params = [];
if ($filtreStatut !== '') { $sql .= " AND a.Statut = ?"; $params[] = $filtreStatut; }
if ($filtreDept !== '') { $sql .= " AND a.ID_departement = ?"; $params[] = $filtreDept; }
if ($filtreResp !== '') { $sql .= " AND a.ID_responsable = ?"; $params[] = $filtreResp; }
if ($filtreType !== '') { $sql .= " AND a.Types = ?"; $params[] = $filtreType; }
if ($recherche !== '') {
    $sql .= " AND (a.Libelle LIKE ? OR ag.Nom LIKE ? OR ag.Prenom LIKE ?)";
    $like = "%$recherche%";
    array_push($params, $like, $like, $like);
}
$sql .= " ORDER BY FIELD(a.Types,'activite','sous-activite','tache'), a.Date_fin ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$activites = $stmt->fetchAll();

$departements = $pdo->query('SELECT * FROM departement ORDER BY Libelle')->fetchAll();
$responsables = $pdo->query("SELECT * FROM agent_dpip WHERE " . sqlEstRespService() . " ORDER BY Nom")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <p class="text-muted mb-0">Toutes les activités, sous-activités et tâches, tous responsables confondus.</p>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/admin/activites_import.php" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-arrow-up"></i> Importer depuis Excel</a>
        <a href="<?= BASE_URL ?>/admin/activite_form.php" class="btn btn-bleu"><i class="bi bi-plus-lg"></i> Nouvelle activité / tâche</a>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-lg-2">
                <label class="form-label small fw-semibold">Recherche</label>
                <input type="text" name="q" class="form-control" value="<?= h($recherche) ?>" placeholder="Libellé, agent...">
            </div>
            <div class="col-lg-2">
                <label class="form-label small fw-semibold">Type</label>
                <select name="type" class="form-select">
                    <option value="">Tous</option>
                    <?php foreach (typesActivite() as $t): ?>
                        <option value="<?= $t ?>" <?= $filtreType === $t ? 'selected' : '' ?>><?= typeActiviteLabel($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2">
                <label class="form-label small fw-semibold">Statut</label>
                <select name="statut" class="form-select">
                    <option value="">Tous</option>
                    <?php foreach (['a_faire','en_cours','en_attente','termine','en_retard','annule'] as $s): ?>
                        <option value="<?= $s ?>" <?= $filtreStatut === $s ? 'selected' : '' ?>><?= statutLabel($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2">
                <label class="form-label small fw-semibold">Département</label>
                <select name="departement" class="form-select">
                    <option value="">Tous</option>
                    <?php foreach ($departements as $d): ?>
                        <option value="<?= $d['id_departement'] ?>" <?= $filtreDept == $d['id_departement'] ? 'selected' : '' ?>><?= h(($d['code_departement'] ?? codeDepartement($d['id_departement'])) . ' · ' . $d['Nom_court']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-3">
                <label class="form-label small fw-semibold">Responsable Service / Sous-directeur</label>
                <select name="responsable" class="form-select">
                    <option value="">Tous</option>
                    <?php foreach ($responsables as $r): ?>
                        <?php $hint = ($r['role'] === 'responsable' && ($r['type_responsable'] ?? 'service') !== 'sous_directeur') ? '' : roleLabelCompte($r); ?>
                        <option value="<?= $r['ID_agent_dpip'] ?>" <?= $filtreResp == $r['ID_agent_dpip'] ? 'selected' : '' ?>><?= h($r['Prenom'] . ' ' . $r['Nom']) ?><?= $hint ? ' (' . h($hint) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-1"><button class="btn btn-outline-secondary w-100"><i class="bi bi-search"></i></button></div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table mb-0">
            <thead><tr><th>Type</th><th>Libellé</th><th>Agent</th><th>Responsable Service / Sous-directeur</th><th>Dépt.</th><th>Échéance</th><th>Progression</th><th>Statut</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($activites as $a): ?>
                <tr>
                    <td><span class="badge <?= typeActiviteBadgeClass($a['Types']) ?>"><?= h(typeActiviteLabel($a['Types'])) ?></span></td>
                    <td>
                        <?= h($a['Libelle']) ?>
                        <?php if (!empty($a['pere_libelle'])): ?>
                            <div class="small text-muted"><i class="bi bi-arrow-return-right"></i> <?= h(typeActiviteLabel($a['pere_type'])) ?> : <?= h($a['pere_libelle']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= h($a['ag_prenom'] . ' ' . $a['ag_nom']) ?></td>
                    <td><?= h($a['resp_prenom'] . ' ' . $a['resp_nom']) ?></td>
                    <td>
                        <?php if (!empty($a['ID_departement'])): ?>
                            <span class="badge bg-dark text-white font-monospace" title="<?= h($a['dept_nom']) ?>"><?= h($a['dept_code'] ?? codeDepartement($a['ID_departement'])) ?></span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="<?= estEnRetard($a['Date_fin'], $a['Statut']) ? 'text-danger fw-semibold' : '' ?>"><?= formatDate($a['Date_fin']) ?></td>
                    <td style="min-width:110px;">
                        <div class="progress"><div class="progress-bar bg-success" style="width:<?= (int)$a['Progression'] ?>%"></div></div>
                        <span class="small text-muted"><?= (int)$a['Progression'] ?>%</span>
                    </td>
                    <td><span class="badge <?= statutBadgeClass($a['Statut']) ?>"><?= statutLabel($a['Statut'], (int)$a['Progression']) ?></span></td>
                    <td class="text-nowrap">
                        <a href="<?= BASE_URL ?>/admin/activite_form.php?id=<?= $a['ID__activites'] ?>" class="btn btn-sm btn-outline-secondary" title="Modifier"><i class="bi bi-pencil"></i></a>
                        <a href="<?= BASE_URL ?>/tache_detail.php?id=<?= $a['ID__activites'] ?>" class="btn btn-sm btn-outline-secondary" title="Détail"><i class="bi bi-eye"></i></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($activites)): ?><tr><td colspan="9" class="text-center text-muted py-4">Aucune activité trouvée.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
