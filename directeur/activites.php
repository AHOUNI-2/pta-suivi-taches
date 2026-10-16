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
    <a href="<?= BASE_URL ?>/directeur/activite_form.php" class="btn btn-bleu"><i class="bi bi-plus-lg"></i> Déléguer une activité</a>
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
    <div class="table-responsive table-activites-wrap">
        <table class="table table-hover table-activites mb-0">
            <thead><tr>
                <th class="col-type">Type</th><th class="col-libelle">Libellé</th><th class="col-personne">Exécutant</th><th class="col-resp">Attribuée par</th><th class="col-dept">Dépt.</th><th class="col-echeance">Échéance</th><th class="col-progression">Progression</th><th class="col-statut">Statut</th><th class="col-actions">Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($activites as $a): ?>
                <tr>
                    <td class="col-type"><span class="badge <?= typeActiviteBadgeClass($a['Types']) ?>"><?= h(typeActiviteLabel($a['Types'])) ?></span></td>
                    <td class="col-libelle"><div class="fw-medium"><?= h($a['Libelle']) ?></div></td>
                    <td class="col-personne">
                        <?= h($a['ag_prenom'] . ' ' . $a['ag_nom']) ?>
                        <?php if ($a['ag_role'] === 'responsable'): ?><div class="mt-1"><span class="badge bg-light text-dark border"><?= ($a['ag_type_resp'] ?? '') === 'sous_directeur' ? 'Sous-directeur' : 'Resp. Service' ?></span></div><?php endif; ?>
                    </td>
                    <td class="col-resp"><?= h($a['resp_prenom'] . ' ' . $a['resp_nom']) ?></td>
                    <td class="col-dept">
                        <?php if (!empty($a['ID_departement'])): ?>
                            <span class="badge bg-dark text-white font-monospace" title="<?= h($a['dept_nom']) ?>"><?= h($a['dept_code'] ?? codeDepartement($a['ID_departement'])) ?></span>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td class="col-echeance <?= estEnRetard($a['Date_fin'], $a['Statut']) ? 'text-danger fw-semibold' : '' ?>"><?= formatDate($a['Date_fin']) ?></td>
                    <td class="col-progression">
                        <div class="prog">
                            <div class="progress" role="progressbar" aria-valuenow="<?= (int)$a['Progression'] ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-success" style="width:<?= (int)$a['Progression'] ?>%"></div></div>
                            <span class="prog-val"><?= (int)$a['Progression'] ?> %</span>
                        </div>
                    </td>
                    <td class="col-statut"><span class="badge <?= statutBadgeClass($a['Statut']) ?>"><?= statutLabel($a['Statut'], (int)$a['Progression']) ?></span></td>
                    <td class="col-actions">
                        <div class="actions">
                            <a href="<?= BASE_URL ?>/directeur/activite_form.php?id=<?= (int)$a['ID__activites'] ?>" class="btn btn-sm btn-outline-primary" title="Modifier" aria-label="Modifier"><i class="bi bi-pencil"></i></a>
                            <a href="<?= BASE_URL ?>/tache_detail.php?id=<?= (int)$a['ID__activites'] ?>" class="btn btn-sm btn-outline-secondary" title="Voir le détail" aria-label="Voir le détail"><i class="bi bi-eye"></i></a>
                            <form method="post" action="<?= BASE_URL ?>/tache_detail.php?id=<?= (int)$a['ID__activites'] ?>" data-confirm="Supprimer définitivement « <?= h(mb_strimwidth($a['Libelle'], 0, 80, '…')) ?> » et tous les éléments qui lui sont rattachés ?">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="supprimer_tache">
                                <button class="btn btn-sm btn-outline-danger" title="Supprimer" aria-label="Supprimer"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($activites)): ?><tr><td colspan="9" class="text-center text-muted py-4">Aucune activité trouvée.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
