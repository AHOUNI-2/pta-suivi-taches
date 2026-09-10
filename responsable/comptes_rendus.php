<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = requireRole(['responsable']);
$pageTitle = 'Comptes rendus reçus';
$respId = (int)$user['ID_agent_dpip'];

$filtreLu = get('lu');
$filtreAgent = get('agent');

$sql = "SELECT cr.*, a.Libelle, a.Types, ag.Nom, ag.Prenom
        FROM comptes_rendus cr
        JOIN activites a ON a.ID__activites = cr.ID_activites
        JOIN agent_dpip ag ON ag.ID_agent_dpip = cr.ID_agent_dpip
        WHERE a.ID_responsable = ?";
$params = [$respId];
if ($filtreLu === '0') { $sql .= ' AND cr.Lu_par_responsable = 0'; }
elseif ($filtreLu === '1') { $sql .= ' AND cr.Lu_par_responsable = 1'; }
if ($filtreAgent !== '') { $sql .= ' AND cr.ID_agent_dpip = ?'; $params[] = $filtreAgent; }
$sql .= ' ORDER BY cr.Date_rendu DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$comptesRendus = $stmt->fetchAll();

$agents = $pdo->prepare("SELECT * FROM agent_dpip WHERE ID_responsable = ? ORDER BY Nom");
$agents->execute([$respId]);
$agents = $agents->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small fw-semibold">Agent</label>
                <select name="agent" class="form-select">
                    <option value="">Tous</option>
                    <?php foreach ($agents as $a): ?>
                        <option value="<?= $a['ID_agent_dpip'] ?>" <?= $filtreAgent == $a['ID_agent_dpip'] ? 'selected' : '' ?>><?= h($a['Prenom'] . ' ' . $a['Nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-semibold">Lecture</label>
                <select name="lu" class="form-select">
                    <option value="">Tous</option>
                    <option value="0" <?= $filtreLu === '0' ? 'selected' : '' ?>>Non lus</option>
                    <option value="1" <?= $filtreLu === '1' ? 'selected' : '' ?>>Lus</option>
                </select>
            </div>
            <div class="col-md-2"><button class="btn btn-outline-secondary w-100"><i class="bi bi-search"></i></button></div>
        </form>
    </div>
</div>

<?php foreach ($comptesRendus as $cr): ?>
    <div class="card mb-2 <?= !$cr['Lu_par_responsable'] ? 'border-primary' : '' ?>">
        <div class="card-body">
            <div class="d-flex justify-content-between flex-wrap gap-2">
                <div>
                    <strong><?= h($cr['Prenom'] . ' ' . $cr['Nom']) ?></strong>
                    <span class="badge <?= typeActiviteBadgeClass($cr['Types']) ?> mx-1"><?= h(typeActiviteLabel($cr['Types'])) ?></span>
                    <span class="text-muted">— <?= h($cr['Libelle']) ?></span>
                </div>
                <span class="text-muted small"><?= formatDate($cr['Date_rendu'], true) ?></span>
            </div>
            <div class="my-2">
                <span class="badge <?= statutBadgeClass($cr['Statut_activite']) ?>"><?= statutLabel($cr['Statut_activite']) ?></span>
                <span class="badge bg-light text-dark border"><?= (int)$cr['Progression'] ?>% de progression</span>
                <?php if (!$cr['Lu_par_responsable']): ?><span class="badge bg-primary">Non lu</span><?php endif; ?>
            </div>
            <p class="mb-2"><?= nl2br(h(mb_strimwidth($cr['Contenu'], 0, 220, '…'))) ?></p>
            <a href="<?= BASE_URL ?>/tache_detail.php?id=<?= $cr['ID_activites'] ?>#comptes-rendus" class="btn btn-sm btn-outline-secondary">Voir la tâche</a>
        </div>
    </div>
<?php endforeach; ?>
<?php if (empty($comptesRendus)): ?>
    <div class="card"><div class="card-body text-center text-muted py-5">Aucun compte rendu trouvé.</div></div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
