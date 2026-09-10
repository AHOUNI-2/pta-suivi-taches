<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = requireRole(['responsable']);
$pageTitle = 'Tableau de bord';
$respId = (int)$user['ID_agent_dpip'];

actualiserTachesEnRetard($pdo);

$calFiltreSql = 'a.ID_responsable = ? OR a.ID_agent_dpip = ?';
$calFiltreParams = [$respId, $respId];
$calNavUrl = BASE_URL . '/responsable/dashboard.php';

$stmt = $pdo->prepare('SELECT Statut, COUNT(*) AS n FROM activites WHERE ID_responsable = ? GROUP BY Statut');
$stmt->execute([$respId]);
$parStatut = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$statutsOrdre = ['a_faire', 'en_cours', 'en_attente', 'termine', 'en_retard', 'annule'];
$ordonne = [];
foreach ($statutsOrdre as $s) { $ordonne[$s] = (int)($parStatut[$s] ?? 0); }
$parStatut = $ordonne;
$total = array_sum($parStatut);

$stmtAgents = $pdo->prepare("SELECT COUNT(*) FROM agent_dpip WHERE ID_responsable = ? AND role='agent'");
$stmtAgents->execute([$respId]);
$nbAgents = (int)$stmtAgents->fetchColumn();

$stmtCrNonLus = $pdo->prepare(
    "SELECT COUNT(*) FROM comptes_rendus cr JOIN activites a ON a.ID__activites = cr.ID_activites
     WHERE a.ID_responsable = ? AND cr.Lu_par_responsable = 0"
);
$stmtCrNonLus->execute([$respId]);
$crNonLus = (int)$stmtCrNonLus->fetchColumn();

$parAgent = $pdo->prepare(
    "SELECT ag.ID_agent_dpip, ag.Nom, ag.Prenom, COUNT(a.ID__activites) AS total, SUM(a.Statut='termine') AS termine, SUM(a.Statut='en_retard') AS retard
     FROM agent_dpip ag LEFT JOIN activites a ON a.ID_agent_dpip = ag.ID_agent_dpip
     WHERE ag.ID_responsable = ? GROUP BY ag.ID_agent_dpip ORDER BY ag.Nom"
);
$parAgent->execute([$respId]);
$parAgent = $parAgent->fetchAll();

$tachesEnRetard = $pdo->prepare(
    "SELECT a.*, ag.Nom, ag.Prenom FROM activites a JOIN agent_dpip ag ON ag.ID_agent_dpip = a.ID_agent_dpip
     WHERE a.ID_responsable = ? AND a.Statut = 'en_retard' ORDER BY a.Date_fin ASC LIMIT 6"
);
$tachesEnRetard->execute([$respId]);
$tachesEnRetard = $tachesEnRetard->fetchAll();

$derniersCr = $pdo->prepare(
    "SELECT cr.*, a.Libelle, ag.Nom, ag.Prenom FROM comptes_rendus cr
     JOIN activites a ON a.ID__activites = cr.ID_activites
     JOIN agent_dpip ag ON ag.ID_agent_dpip = cr.ID_agent_dpip
     WHERE a.ID_responsable = ? ORDER BY cr.Date_rendu DESC LIMIT 6"
);
$derniersCr->execute([$respId]);
$derniersCr = $derniersCr->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="card mb-4">
    <div class="card-header">Calendrier des activités (les miennes et celles que j'ai attribuées)</div>
    <div class="card-body"><?php include __DIR__ . '/../includes/calendrier.php'; ?></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#e7edf5;color:var(--pta-navy)"><i class="bi bi-list-task"></i></div>
            <div><div class="stat-value"><?= $total ?></div><div class="stat-label">Tâches attribuées</div></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#e7f7ee;color:var(--pta-green)"><i class="bi bi-check2-circle"></i></div>
            <div><div class="stat-value"><?= (int)$parStatut['termine'] ?> <span class="text-muted fs-6">/ <?= $total ?></span></div><div class="stat-label">Terminées (<?= pourcentage((int)$parStatut['termine'], $total) ?> %)</div></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#fdecea;color:#dc3545"><i class="bi bi-exclamation-triangle"></i></div>
            <div><div class="stat-value"><?= (int)$parStatut['en_retard'] ?> <span class="text-muted fs-6">/ <?= $total ?></span></div><div class="stat-label">En retard (<?= pourcentage((int)$parStatut['en_retard'], $total) ?> %)</div></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#fff3e6;color:var(--pta-orange)"><i class="bi bi-chat-square-text"></i></div>
            <div><div class="stat-value"><?= $crNonLus ?></div><div class="stat-label">Comptes rendus non lus</div></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">Répartition des tâches par statut</div>
            <div class="card-body">
                <canvas id="chartStatuts" height="220"></canvas>
                <?= legendeStatuts($parStatut) ?>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between">
                <span>Mes agents (<?= $nbAgents ?>)</span>
                <a href="<?= BASE_URL ?>/responsable/agents.php" class="small">Voir tout</a>
            </div>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead><tr><th>Agent</th><th>Total</th><th>Terminées</th><th>En retard</th></tr></thead>
                    <tbody>
                    <?php foreach ($parAgent as $a): ?>
                        <tr>
                            <td><?= h($a['Prenom'] . ' ' . $a['Nom']) ?></td>
                            <td><?= (int)$a['total'] ?></td>
                            <td class="text-success"><?= (int)$a['termine'] ?></td>
                            <td class="text-danger"><?= (int)$a['retard'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($parAgent)): ?><tr><td colspan="4" class="text-center text-muted py-3">Aucun agent rattaché.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header text-danger"><i class="bi bi-exclamation-triangle"></i> Tâches en retard</div>
            <div class="list-group list-group-flush">
                <?php foreach ($tachesEnRetard as $t): ?>
                    <a href="<?= BASE_URL ?>/tache_detail.php?id=<?= $t['ID__activites'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between">
                        <span><?= h($t['Libelle']) ?><div class="text-muted small"><?= h($t['Prenom'] . ' ' . $t['Nom']) ?></div></span>
                        <span class="text-danger small"><?= formatDate($t['Date_fin']) ?></span>
                    </a>
                <?php endforeach; ?>
                <?php if (empty($tachesEnRetard)): ?><div class="p-3 text-center text-muted">Aucune tâche en retard 🎉</div><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">Derniers comptes rendus</div>
            <div class="list-group list-group-flush">
                <?php foreach ($derniersCr as $cr): ?>
                    <a href="<?= BASE_URL ?>/tache_detail.php?id=<?= $cr['ID_activites'] ?>#comptes-rendus" class="list-group-item list-group-item-action <?= !$cr['Lu_par_responsable'] ? 'bg-light' : '' ?>">
                        <div class="d-flex justify-content-between">
                            <strong><?= h($cr['Prenom'] . ' ' . $cr['Nom']) ?></strong>
                            <span class="text-muted small"><?= timeAgo($cr['Date_rendu']) ?></span>
                        </div>
                        <div class="small text-muted"><?= h($cr['Libelle']) ?></div>
                    </a>
                <?php endforeach; ?>
                <?php if (empty($derniersCr)): ?><div class="p-3 text-center text-muted">Aucun compte rendu reçu.</div><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$extraScripts = '<script src="' . BASE_URL . '/assets/vendor/chartjs/chart.umd.min.js"></script><script>
new Chart(document.getElementById("chartStatuts"), {
  type: "doughnut",
  data: { labels: ' . json_encode(array_map('statutLabel', $statutsOrdre)) . ', datasets: [{ data: ' . json_encode(array_values($parStatut)) . ', backgroundColor: ' . json_encode(array_map('statutColor', $statutsOrdre)) . ' }] },
  options: { plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => c.label + " : " + c.parsed } } } }
});
</script>';
include __DIR__ . '/../includes/footer.php';
?>
