<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = requireRole(['admin']);
$pageTitle = 'Rapports & statistiques';
actualiserTachesEnRetard($pdo);

$parPrioriteBrut = $pdo->query('SELECT Priorite, COUNT(*) AS n FROM activites GROUP BY Priorite')->fetchAll(PDO::FETCH_KEY_PAIR);
// Priorités standard d'abord (toujours affichées), puis les valeurs libres éventuelles.
$parPriorite = [];
foreach (prioritesActivite() as $p) { $parPriorite[$p] = (int)($parPrioriteBrut[$p] ?? 0); }
foreach ($parPrioriteBrut as $p => $n) { if (!isset($parPriorite[$p])) $parPriorite[$p] = (int)$n; }

$parMois = $pdo->query(
    "SELECT DATE_FORMAT(Date_creation, '%Y-%m') AS mois, COUNT(*) AS n
     FROM activites WHERE Date_creation >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
     GROUP BY mois ORDER BY mois"
)->fetchAll();

// Performance par agent : tout compte « agent » (rôle ou fonction) + toute personne
// à qui au moins une activité a été confiée (même si son rôle principal est autre).
$parAgent = $pdo->query(
    "SELECT ag.ID_agent_dpip, ag.Nom, ag.Prenom, ag.role, ag.est_agent, d.Nom_court AS dept,
            COUNT(a.ID__activites) AS total,
            SUM(a.Statut='termine') AS termine,
            SUM(a.Statut='en_retard') AS retard,
            ROUND(AVG(a.Progression)) AS avg_progression
     FROM agent_dpip ag
     LEFT JOIN activites a ON a.ID_agent_dpip = ag.ID_agent_dpip
     LEFT JOIN departement d ON d.id_departement = ag.ID_departement
     WHERE " . sqlEstAgent('ag.') . "
        OR ag.ID_agent_dpip IN (SELECT ID_agent_dpip FROM activites)
     GROUP BY ag.ID_agent_dpip
     ORDER BY total DESC, ag.Nom"
)->fetchAll();

// Activité par responsable service / sous-directeur : tout compte responsable service
// (rôle ou fonction) + toute personne qui a attribué au moins une activité.
$parResponsable = $pdo->query(
    "SELECT r.ID_agent_dpip, r.Nom, r.Prenom, r.role, r.type_responsable, r.est_responsable_service,
            COUNT(a.ID__activites) AS total_attribuees,
            SUM(a.Statut='termine') AS termine,
            (SELECT COUNT(DISTINCT ag2.ID_agent_dpip) FROM agent_dpip ag2 WHERE ag2.ID_responsable = r.ID_agent_dpip) AS nb_agents
     FROM agent_dpip r
     LEFT JOIN activites a ON a.ID_responsable = r.ID_agent_dpip
     WHERE " . sqlEstRespService('r.') . "
        OR r.ID_agent_dpip IN (SELECT ID_responsable FROM activites)
     GROUP BY r.ID_agent_dpip
     ORDER BY total_attribuees DESC, r.Nom"
)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">Tâches créées par mois (12 derniers mois)</div>
            <div class="card-body"><canvas id="chartMois" height="200"></canvas></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header">Répartition par priorité</div>
            <div class="card-body"><canvas id="chartPriorite" height="200"></canvas></div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">Performance par agent</div>
    <div class="table-responsive">
        <table class="table mb-0">
            <thead><tr><th>Agent</th><th>Département</th><th>Total tâches</th><th>Terminées</th><th>En retard</th><th>Progression moyenne</th></tr></thead>
            <tbody>
            <?php foreach ($parAgent as $a): ?>
                <tr>
                    <td>
                        <?= h($a['Prenom'] . ' ' . $a['Nom']) ?>
                        <?php if ($a['role'] !== 'agent'): ?>
                            <span class="badge bg-light text-dark border small"><?= h(roleLabel($a['role'])) ?><?= !empty($a['est_agent']) ? ' + Agent' : '' ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= h($a['dept'] ?? '—') ?></td>
                    <td><?= (int)$a['total'] ?></td>
                    <td><span class="text-success"><?= (int)$a['termine'] ?></span></td>
                    <td><span class="text-danger"><?= (int)$a['retard'] ?></span></td>
                    <td style="min-width:120px;">
                        <div class="progress"><div class="progress-bar" style="width:<?= (int)($a['avg_progression'] ?? 0) ?>%"></div></div>
                        <span class="small text-muted"><?= (int)($a['avg_progression'] ?? 0) ?>%</span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">Activité par responsable service / sous-directeur</div>
    <div class="table-responsive">
        <table class="table mb-0">
            <thead><tr><th>Responsable Service / Sous-directeur</th><th>Agents gérés</th><th>Tâches attribuées</th><th>Terminées</th></tr></thead>
            <tbody>
            <?php foreach ($parResponsable as $r): ?>
                <tr>
                    <td>
                        <?= h($r['Prenom'] . ' ' . $r['Nom']) ?>
                        <span class="badge bg-light text-dark border small"><?= h(roleLabelCompte($r)) ?></span>
                    </td>
                    <td><?= (int)$r['nb_agents'] ?></td>
                    <td><?= (int)$r['total_attribuees'] ?></td>
                    <td><span class="text-success"><?= (int)$r['termine'] ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$moisLabels = array_map(function($m) {
    $dt = DateTime::createFromFormat('Y-m-d', $m['mois'] . '-01');
    $mois = ['Jan','Fév','Mar','Avr','Mai','Juin','Juil','Août','Sep','Oct','Nov','Déc'];
    return $mois[(int)$dt->format('n') - 1] . ' ' . $dt->format('Y');
}, $parMois);
$moisData = array_map('intval', array_column($parMois, 'n'));

$extraScripts = '<script src="' . BASE_URL . '/assets/vendor/chartjs/chart.umd.min.js"></script><script>
new Chart(document.getElementById("chartMois"), {
  type: "line",
  data: { labels: ' . json_encode($moisLabels) . ', datasets: [{ label: "Tâches créées", data: ' . json_encode($moisData) . ', borderColor: "#0a4d0a", backgroundColor: "rgba(10,77,10,0.08)", fill: true, tension: 0.3 }] },
  options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});
new Chart(document.getElementById("chartPriorite"), {
  type: "bar",
  data: { labels: ' . json_encode(array_map('prioriteLabel', array_keys($parPriorite))) . ', datasets: [{ data: ' . json_encode(array_values($parPriorite)) . ', backgroundColor: ' . json_encode(array_map(function ($p) {
    return ['basse' => '#adb5bd', 'moyenne' => '#0dcaf0', 'haute' => '#ffc107', 'urgente' => '#dc3545'][$p] ?? '#6f42c1';
}, array_keys($parPriorite))) . ', borderRadius: 4 }] },
  options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});
</script>';
include __DIR__ . '/../includes/footer.php';
?>
