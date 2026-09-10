<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = requireRole(['agent']);
$pageTitle = 'Mes comptes rendus';
$agentId = (int)$user['ID_agent_dpip'];

$stmt = $pdo->prepare(
    "SELECT cr.*, a.Libelle, a.Types FROM comptes_rendus cr
     JOIN activites a ON a.ID__activites = cr.ID_activites
     WHERE cr.ID_agent_dpip = ? ORDER BY cr.Date_rendu DESC"
);
$stmt->execute([$agentId]);
$comptesRendus = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<p class="text-muted">Historique de tous les comptes rendus que vous avez soumis à votre responsable.</p>

<?php foreach ($comptesRendus as $cr): ?>
    <div class="card mb-2">
        <div class="card-body">
            <div class="d-flex justify-content-between flex-wrap gap-2">
                <span><span class="badge <?= typeActiviteBadgeClass($cr['Types']) ?> me-1"><?= h(typeActiviteLabel($cr['Types'])) ?></span><strong><?= h($cr['Libelle']) ?></strong></span>
                <span class="text-muted small"><?= formatDate($cr['Date_rendu'], true) ?></span>
            </div>
            <div class="my-2">
                <span class="badge <?= statutBadgeClass($cr['Statut_activite']) ?>"><?= statutLabel($cr['Statut_activite']) ?></span>
                <span class="badge bg-light text-dark border"><?= (int)$cr['Progression'] ?>%</span>
                <?php if ($cr['Lu_par_responsable']): ?>
                    <span class="badge bg-success"><i class="bi bi-check2-all"></i> Lu par le responsable</span>
                <?php else: ?>
                    <span class="badge bg-secondary">En attente de lecture</span>
                <?php endif; ?>
            </div>
            <p class="mb-2"><?= nl2br(h($cr['Contenu'])) ?></p>
            <a href="<?= BASE_URL ?>/tache_detail.php?id=<?= $cr['ID_activites'] ?>#comptes-rendus" class="btn btn-sm btn-outline-secondary">Voir la tâche</a>
        </div>
    </div>
<?php endforeach; ?>
<?php if (empty($comptesRendus)): ?>
    <div class="card"><div class="card-body text-center text-muted py-5">Vous n'avez soumis aucun compte rendu pour le moment.</div></div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
