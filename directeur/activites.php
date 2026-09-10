<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$directeur = requireDirecteurAccess($pdo);
$pageTitle = 'Activités & tâches';
$dirId = (int)$directeur['ID_agent_dpip'];
actualiserTachesEnRetard($pdo);

$filtreStatut = get('statut');
$filtreDept   = get('departement');
$filtreResp   = get('responsable');
$filtreType   = get('type');
$filtrePorte  = get('porte'); // 'moi' = uniquement mes délégations
$recherche    = get('q');

$sql = "SELECT a.*, ag.Nom AS ag_nom, ag.Prenom AS ag_prenom, ag.role AS ag_role, ag.type_responsable AS ag_type_resp,
               r.Nom AS resp_nom, r.Prenom AS resp_prenom,
               d.Nom_court AS dept_nom, d.code_departement AS dept_code
        FROM activites a
        JOIN agent_dpip ag ON ag.ID_agent_dpip = a.ID_agent_dpip
        JOIN agent_dpip r  ON r.ID_agent_dpip = a.ID_responsable
        LEFT JOIN departement d ON d.id_departement = a.ID_departement
        WHERE 1=1";
$params = [];
if ($filtrePorte === 'moi') { $sql .= " AND a.ID_responsable = ?"; $params[] = $dirId; }
if ($filtreStatut !== '') { $sql .= " AND a.Statut = ?"; $params[] = $filtreStatut; }
if ($filtreDept !== '')   { $sql .= " AND a.ID_departement = ?"; $params[] = $filtreDept; }
if ($filtreResp !== '')   { $sql .= " AND a.ID_responsable = ?"; $params[] = $filtreResp; }
if ($filtreType !== '')   { $sql .= " AND a.Types = ?"; $params[] = $filtreType; }
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
$responsables = $pdo->query("SELECT * FROM agent_dpip WHERE role IN ('directeur','responsable') OR est_responsable_service = 1 ORDER BY Nom")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <p class="text-muted mb-0">Toutes les activités de toutes les directions.</p>
    <a href="<?= BASE_URL ?>/directeur/activite_form.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Déléguer une activité</a>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-semibold">Recherche</label>
                <input type="text" name="q" class="form-control" value="<?= h($recherche) ?>" placeholder="Libellé, agent...">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Portée</label>
                <select name="porte" class="form-select">
                    <option value="">Toutes</option>
                    <option value="moi" <?= $filtrePorte === 'moi' ? 'selected' : '' ?>>Mes délégations</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Type</label>
                <select name="type" class="form-select">
                    <option value="">Tous</option>
                    <?php foreach (typesActivite() as $t): ?>
                        <option value="<?= $t ?>" <?= $filtreType === $t ? 'selected' : '' ?>><?= typeActiviteLabel($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Statut</label>
                <select name="statut" class="form-select">
                    <option value="">Tous</option>
                    <?php foreach (['a_faire','en_cours','en_attente','termine','en_retard','annule'] as $s): ?>
                        <option value="<?= $s ?>" <?= $filtreStatut === $s ? 'selected' : '' ?>><?= statutLabel($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Département</label>
                <select name="departement" class="form-select">
                    <option value="">Tous</option>
                    <?php foreach ($departements as $d): ?>
                        <option value="<?= $d['id_departement'] ?>" <?= $filtreDept == $d['id_departement'] ? 'selected' : '' ?>><?= h(($d['code_departement'] ?? codeDepartement($d['id_departement'])) . ' · ' . $d['Nom_court']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1"><button class="btn btn-outline-secondary w-100"><i class="bi bi-search"></i></button></div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table mb-0">
            <thead><tr><th>Type</th><th>Libellé</th><th>Exécutant</th><th>Attribuée par</th><th>Dépt.</th><th>Échéance</th><th>Progression</th><th>Statut</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($activites as $a): ?>
                <tr>
                    <td><span class="badge <?= typeActiviteBadgeClass($a['Types']) ?>"><?= h(typeActiviteLabel($a['Types'])) ?></span></td>
                    <td><?= h($a['Libelle']) ?></td>
                    <td>
                        <?= h($a['ag_prenom'] . ' ' . $a['ag_nom']) ?>
                        <?php if ($a['ag_role'] === 'responsable'): ?><span class="badge bg-light text-dark border"><?= ($a['ag_type_resp'] ?? '') === 'sous_directeur' ? 'Sous-directeur' : 'Resp. Service' ?></span><?php endif; ?>
                    </td>
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
                    <td><span class="badge <?= statutBadgeClass($a['Statut']) ?>"><?= statutLabel($a['Statut']) ?></span></td>
                    <td class="text-nowrap">
                        <?php if ((int)$a['ID_responsable'] === $dirId): ?>
                            <a href="<?= BASE_URL ?>/directeur/activite_form.php?id=<?= $a['ID__activites'] ?>" class="btn btn-sm btn-outline-secondary" title="Modifier"><i class="bi bi-pencil"></i></a>
                        <?php endif; ?>
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
