<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = requireRole(['responsable']);
$pageTitle = 'Activités & tâches';
$respId = (int)$user['ID_agent_dpip'];
actualiserTachesEnRetard($pdo);

$filtreStatut = get('statut');
$filtreAgent = get('agent');
$filtreType = get('type');
$recherche = get('q');

$sql = "SELECT a.*, ag.Nom AS ag_nom, ag.Prenom AS ag_prenom, r.Nom AS resp_nom, r.Prenom AS resp_prenom
        FROM activites a
        JOIN agent_dpip ag ON ag.ID_agent_dpip = a.ID_agent_dpip
        JOIN agent_dpip r ON r.ID_agent_dpip = a.ID_responsable
        WHERE (a.ID_responsable = ? OR ag.ID_responsable = ?)";
$params = [$respId, $respId];
if ($filtreStatut !== '') { $sql .= " AND a.Statut = ?"; $params[] = $filtreStatut; }
if ($filtreAgent !== '') { $sql .= " AND a.ID_agent_dpip = ?"; $params[] = $filtreAgent; }
if ($filtreType !== '') { $sql .= " AND a.Types = ?"; $params[] = $filtreType; }
if ($recherche !== '') { $sql .= " AND a.Libelle LIKE ?"; $params[] = "%$recherche%"; }
$sql .= " ORDER BY FIELD(a.Types,'activite','sous-activite','tache'), a.Date_fin ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$activites = $stmt->fetchAll();

$agents = $pdo->prepare("SELECT * FROM agent_dpip WHERE ID_responsable = ? ORDER BY Nom");
$agents->execute([$respId]);
$agents = $agents->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <p class="text-muted mb-0">Activités, sous-activités et tâches que vous avez attribuées, ou qui sont confiées à vos agents. Vous pouvez les modifier ou les supprimer.</p>
    <a href="<?= BASE_URL ?>/responsable/activite_form.php" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nouvelle activité / tâche</a>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-semibold">Recherche</label>
                <input type="text" name="q" class="form-control" value="<?= h($recherche) ?>">
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
            <div class="col-md-3">
                <label class="form-label small fw-semibold">Agent</label>
                <select name="agent" class="form-select">
                    <option value="">Tous</option>
                    <?php foreach ($agents as $a): ?>
                        <option value="<?= $a['ID_agent_dpip'] ?>" <?= $filtreAgent == $a['ID_agent_dpip'] ? 'selected' : '' ?>><?= h($a['Prenom'] . ' ' . $a['Nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2"><button class="btn btn-outline-secondary w-100"><i class="bi bi-search"></i></button></div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover table-activites mb-0">
            <thead><tr>
                <th class="col-type">Type</th><th class="col-libelle">Libellé</th><th class="col-personne">Agent</th><th class="col-prio">Priorité</th><th class="col-echeance">Échéance</th><th class="col-progression">Progression</th><th class="col-statut">Statut</th><th class="col-actions">Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($activites as $a): ?>
                <tr>
                    <td class="col-type"><span class="badge <?= typeActiviteBadgeClass($a['Types']) ?>"><?= h(typeActiviteLabel($a['Types'])) ?></span></td>
                    <td class="col-libelle">
                        <div class="fw-medium"><?= h($a['Libelle']) ?></div>
                        <?php if ((int)$a['ID_responsable'] !== $respId): ?><div class="small text-muted mt-1">Attribuée par <?= h($a['resp_prenom'] . ' ' . $a['resp_nom']) ?></div><?php endif; ?>
                    </td>
                    <td class="col-personne"><?= h($a['ag_prenom'] . ' ' . $a['ag_nom']) ?></td>
                    <td class="col-prio"><span class="badge <?= prioriteBadgeClass($a['Priorite']) ?>"><?= prioriteLabel($a['Priorite']) ?></span></td>
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
                            <a href="<?= BASE_URL ?>/responsable/activite_form.php?id=<?= (int)$a['ID__activites'] ?>" class="btn btn-sm btn-outline-primary" title="Modifier" aria-label="Modifier"><i class="bi bi-pencil"></i></a>
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
            <?php if (empty($activites)): ?><tr><td colspan="8" class="text-center text-muted py-4">Aucune activité trouvée.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
