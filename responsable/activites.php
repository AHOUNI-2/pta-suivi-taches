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

$sql = "SELECT a.*, ag.Nom AS ag_nom, ag.Prenom AS ag_prenom
        FROM activites a JOIN agent_dpip ag ON ag.ID_agent_dpip = a.ID_agent_dpip
        WHERE a.ID_responsable = ?";
$params = [$respId];
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
    <p class="text-muted mb-0">Activités, sous-activités et tâches que vous avez attribuées à vos agents.</p>
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
        <table class="table mb-0">
            <thead><tr><th>Type</th><th>Libellé</th><th>Agent</th><th>Priorité</th><th>Échéance</th><th>Progression</th><th>Statut</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($activites as $a): ?>
                <tr>
                    <td><span class="badge <?= typeActiviteBadgeClass($a['Types']) ?>"><?= h(typeActiviteLabel($a['Types'])) ?></span></td>
                    <td><?= h($a['Libelle']) ?></td>
                    <td><?= h($a['ag_prenom'] . ' ' . $a['ag_nom']) ?></td>
                    <td><span class="badge <?= prioriteBadgeClass($a['Priorite']) ?>"><?= prioriteLabel($a['Priorite']) ?></span></td>
                    <td class="<?= estEnRetard($a['Date_fin'], $a['Statut']) ? 'text-danger fw-semibold' : '' ?>"><?= formatDate($a['Date_fin']) ?></td>
                    <td style="min-width:110px;">
                        <div class="progress"><div class="progress-bar bg-success" style="width:<?= (int)$a['Progression'] ?>%"></div></div>
                        <span class="small text-muted"><?= (int)$a['Progression'] ?>%</span>
                    </td>
                    <td><span class="badge <?= statutBadgeClass($a['Statut']) ?>"><?= statutLabel($a['Statut']) ?></span></td>
                    <td class="text-nowrap">
                        <a href="<?= BASE_URL ?>/responsable/activite_form.php?id=<?= $a['ID__activites'] ?>" class="btn btn-sm btn-outline-secondary" title="Modifier"><i class="bi bi-pencil"></i></a>
                        <a href="<?= BASE_URL ?>/tache_detail.php?id=<?= $a['ID__activites'] ?>" class="btn btn-sm btn-outline-secondary" title="Détail"><i class="bi bi-eye"></i></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($activites)): ?><tr><td colspan="8" class="text-center text-muted py-4">Aucune activité trouvée.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
