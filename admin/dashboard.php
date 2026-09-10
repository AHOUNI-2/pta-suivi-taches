<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = requireRole(['admin']);
$pageTitle = 'Tableau de bord';

actualiserTachesEnRetard($pdo);

$calFiltreSql = '';
$calFiltreParams = [];
$calNavUrl = BASE_URL . '/admin/dashboard.php';

$totalTaches = (int)$pdo->query('SELECT COUNT(*) FROM activites')->fetchColumn();
$parStatut = $pdo->query('SELECT Statut, COUNT(*) AS n FROM activites GROUP BY Statut')->fetchAll(PDO::FETCH_KEY_PAIR);

$parDepartement = $pdo->query(
    "SELECT d.Nom_court AS dept, COUNT(a.ID__activites) AS n
     FROM departement d LEFT JOIN activites a ON a.ID_departement = d.id_departement
     GROUP BY d.id_departement ORDER BY d.Nom_court"
)->fetchAll();

$tachesEnRetard = $pdo->query(
    "SELECT a.*, ag.Nom, ag.Prenom, d.Nom_court AS dept
     FROM activites a
     JOIN agent_dpip ag ON ag.ID_agent_dpip = a.ID_agent_dpip
     LEFT JOIN departement d ON d.id_departement = a.ID_departement
     WHERE a.Statut = 'en_retard'
     ORDER BY a.Date_fin ASC LIMIT 8"
)->fetchAll();

$activiteRecente = $pdo->query(
    "SELECT j.*, a.Libelle AS tache_libelle, u.Nom, u.Prenom
     FROM journal_activites j
     JOIN activites a ON a.ID__activites = j.ID_activites
     LEFT JOIN agent_dpip u ON u.ID_agent_dpip = j.ID_agent_dpip
     ORDER BY j.Date_action DESC LIMIT 10"
)->fetchAll();

$statutsOrdre = ['a_faire', 'en_cours', 'en_attente', 'termine', 'en_retard', 'annule'];
$ordonne = [];
foreach ($statutsOrdre as $s) { $ordonne[$s] = (int)($parStatut[$s] ?? 0); }
$parStatut = $ordonne;

include __DIR__ . '/../includes/header.php';
?>
<div class="card mb-4">
    <div class="card-header">Calendrier des activités (toutes directions)</div>
    <div class="card-body"><?php include __DIR__ . '/../includes/calendrier.php'; ?></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-4">
        <div class="stat-card">
            <div class="stat-icon" style="background:#e7edf5;color:var(--pta-navy)"><i class="bi bi-list-task"></i></div>
            <div><div class="stat-value"><?= $totalTaches ?></div><div class="stat-label">Tâches au total</div></div>
        </div>
    </div>
    <div class="col-6 col-lg-4">
        <div class="stat-card">
            <div class="stat-icon" style="background:#e7f7ee;color:var(--pta-green)"><i class="bi bi-check2-circle"></i></div>
            <div><div class="stat-value"><?= (int)$parStatut['termine'] ?> <span class="text-muted fs-6">/ <?= $totalTaches ?></span></div><div class="stat-label">Terminées (<?= pourcentage((int)$parStatut['termine'], $totalTaches) ?> %)</div></div>
        </div>
    </div>
    <div class="col-6 col-lg-4">
        <div class="stat-card">
            <div class="stat-icon" style="background:#fdecea;color:#dc3545"><i class="bi bi-exclamation-triangle"></i></div>
            <div><div class="stat-value"><?= (int)$parStatut['en_retard'] ?> <span class="text-muted fs-6">/ <?= $totalTaches ?></span></div><div class="stat-label">En retard (<?= pourcentage((int)$parStatut['en_retard'], $totalTaches) ?> %)</div></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header">Répartition des tâches par statut</div>
            <div class="card-body">
                <canvas id="chartStatuts" height="220"></canvas>
                <?= legendeStatuts($parStatut) ?>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header">Tâches par département</div>
            <div class="card-body"><canvas id="chartDept" height="220"></canvas></div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header text-danger"><i class="bi bi-exclamation-triangle"></i> Tâches en retard</div>
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead><tr><th>Tâche</th><th>Agent</th><th>Échéance</th></tr></thead>
                    <tbody>
                    <?php foreach ($tachesEnRetard as $t): ?>
                        <tr>
                            <td><?= h($t['Libelle']) ?><br><span class="badge bg-light text-dark border"><?= h($t['dept']) ?></span></td>
                            <td><?= h($t['Prenom'] . ' ' . $t['Nom']) ?></td>
                            <td class="text-danger fw-semibold"><?= formatDate($t['Date_fin']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($tachesEnRetard)): ?>
                        <tr><td colspan="3" class="text-center text-muted py-4">Aucune tâche en retard 🎉</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">Activité récente</div>
            <div class="card-body">
                <div class="timeline">
                    <?php foreach ($activiteRecente as $a): ?>
                        <div class="timeline-item">
                            <div class="small text-muted"><?= timeAgo($a['Date_action']) ?></div>
                            <div><strong><?= h(($a['Prenom'] ?? '') . ' ' . ($a['Nom'] ?? 'Système')) ?></strong> — <?= h($a['Detail'] ?: $a['Action']) ?></div>
                            <div class="small text-muted">Tâche : <?= h($a['tache_libelle']) ?></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($activiteRecente)): ?>
                        <p class="text-muted mb-0">Aucune activité récente.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$extraScripts = '<script src="' . BASE_URL . '/assets/vendor/chartjs/chart.umd.min.js"></script><script>
const statutsData = ' . json_encode(array_values($parStatut)) . ';
const statutsLabels = ' . json_encode(array_map('statutLabel', $statutsOrdre)) . ';
new Chart(document.getElementById("chartStatuts"), {
  type: "doughnut",
  data: { labels: statutsLabels, datasets: [{ data: statutsData, backgroundColor: ' . json_encode(array_map('statutColor', $statutsOrdre)) . ' }] },
  options: { plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => c.label + " : " + c.parsed } } } }
});
const deptLabels = ' . json_encode(array_column($parDepartement, 'dept')) . ';
const deptData = ' . json_encode(array_map('intval', array_column($parDepartement, 'n'))) . ';
new Chart(document.getElementById("chartDept"), {
  type: "bar",
  data: { labels: deptLabels, datasets: [{ label: "Tâches", data: deptData, backgroundColor: "#0f2942", borderRadius: 4 }] },
  options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});
</script>';
include __DIR__ . '/../includes/footer.php';
?>
