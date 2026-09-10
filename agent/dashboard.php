<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = requireRole(['agent']);
$pageTitle = 'Tableau de bord';
$agentId = (int)$user['ID_agent_dpip'];
actualiserTachesEnRetard($pdo);

$calFiltreSql = 'a.ID_agent_dpip = ?';
$calFiltreParams = [$agentId];
$calNavUrl = BASE_URL . '/agent/dashboard.php';

$stmt = $pdo->prepare('SELECT Statut, COUNT(*) AS n FROM activites WHERE ID_agent_dpip = ? GROUP BY Statut');
$stmt->execute([$agentId]);
$parStatut = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$statutsOrdre = ['a_faire', 'en_cours', 'en_attente', 'termine', 'en_retard', 'annule'];
$ordonne = [];
foreach ($statutsOrdre as $s) { $ordonne[$s] = (int)($parStatut[$s] ?? 0); }
$parStatut = $ordonne;
$total = array_sum($parStatut);

$prochaines = $pdo->prepare(
    "SELECT * FROM activites WHERE ID_agent_dpip = ? AND Statut NOT IN ('termine','annule') ORDER BY Date_fin ASC LIMIT 6"
);
$prochaines->execute([$agentId]);
$prochaines = $prochaines->fetchAll();

// « Observations de mon responsable » : on regroupe
//  1) les observations horodatées (table observation) ;
//  2) le champ Observation porté directement sur l'activité (saisi par le responsable / l'admin).
$observationsRecentes = $pdo->prepare(
    "SELECT * FROM (
        SELECT o.id_activites            AS id_activites,
               a.Libelle                 AS Libelle,
               o.libelle                 AS texte,
               r.Nom                     AS Nom,
               r.Prenom                  AS Prenom,
               o.Date_creation           AS Date_creation,
               'remarque'                AS source
        FROM observation o
        JOIN activites a  ON a.ID__activites = o.id_activites
        JOIN agent_dpip r ON r.ID_agent_dpip = o.ID_agent_dpip
        WHERE a.ID_agent_dpip = ?

        UNION ALL

        SELECT a.ID__activites           AS id_activites,
               a.Libelle                 AS Libelle,
               a.Observation             AS texte,
               r.Nom                     AS Nom,
               r.Prenom                  AS Prenom,
               a.Date_modification        AS Date_creation,
               'activite'                AS source
        FROM activites a
        JOIN agent_dpip r ON r.ID_agent_dpip = a.ID_responsable
        WHERE a.ID_agent_dpip = ? AND a.Observation IS NOT NULL AND TRIM(a.Observation) <> ''
    ) obs
    ORDER BY Date_creation DESC
    LIMIT 6"
);
$observationsRecentes->execute([$agentId, $agentId]);
$observationsRecentes = $observationsRecentes->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<div class="card mb-4">
    <div class="card-header">Calendrier de mes activités</div>
    <div class="card-body"><?php include __DIR__ . '/../includes/calendrier.php'; ?></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#e7edf5;color:var(--pta-navy)"><i class="bi bi-list-task"></i></div>
            <div><div class="stat-value"><?= $total ?></div><div class="stat-label">Mes activités &amp; tâches</div></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#eaf1fb;color:#0d6efd"><i class="bi bi-arrow-repeat"></i></div>
            <div><div class="stat-value"><?= $parStatut['en_cours'] ?></div><div class="stat-label">En cours</div></div>
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
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">Mes tâches par statut</div>
            <div class="card-body">
                <canvas id="chartStatuts" height="220"></canvas>
                <?= legendeStatuts($parStatut) ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">Prochaines échéances</div>
            <div class="list-group list-group-flush">
                <?php foreach ($prochaines as $t): ?>
                    <a href="<?= BASE_URL ?>/tache_detail.php?id=<?= $t['ID__activites'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-2">
                        <span><span class="badge <?= typeActiviteBadgeClass($t['Types']) ?> me-1"><?= h(typeActiviteLabel($t['Types'])) ?></span><?= h($t['Libelle']) ?></span>
                        <span class="small text-nowrap <?= estEnRetard($t['Date_fin'], $t['Statut']) ? 'text-danger fw-semibold' : 'text-muted' ?>"><?= formatDate($t['Date_fin']) ?></span>
                    </a>
                <?php endforeach; ?>
                <?php if (empty($prochaines)): ?><div class="p-3 text-center text-muted">Aucune tâche en cours 🎉</div><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">Observations de mon responsable</div>
            <div class="list-group list-group-flush">
                <?php foreach ($observationsRecentes as $o): ?>
                    <a href="<?= BASE_URL ?>/tache_detail.php?id=<?= $o['id_activites'] ?><?= $o['source'] === 'remarque' ? '#observations' : '' ?>" class="list-group-item list-group-item-action">
                        <div class="small text-muted d-flex justify-content-between gap-2">
                            <span><?= h($o['Prenom'] . ' ' . $o['Nom']) ?> — <?= timeAgo($o['Date_creation']) ?></span>
                            <?php if ($o['source'] === 'activite'): ?><span class="badge bg-light text-dark border">sur l'activité</span><?php endif; ?>
                        </div>
                        <div class="fw-semibold small text-truncate"><?= h($o['Libelle']) ?></div>
                        <div><?= h(mb_strimwidth((string)$o['texte'], 0, 90, '…')) ?></div>
                    </a>
                <?php endforeach; ?>
                <?php if (empty($observationsRecentes)): ?><div class="p-3 text-center text-muted">Aucune observation.</div><?php endif; ?>
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
