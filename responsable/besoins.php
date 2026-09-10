<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = requireRole(['responsable']);
$pageTitle = 'Besoins exprimés';
$respId = (int)$user['ID_agent_dpip'];

$stmt = $pdo->prepare(
    "SELECT b.*, a.Libelle AS tache_libelle, a.ID__activites, ag.Nom, ag.Prenom
     FROM besions b
     JOIN activites a ON a.ID__activites = b.ID_activites
     JOIN agent_dpip ag ON ag.ID_agent_dpip = a.ID_agent_dpip
     WHERE a.ID_responsable = ?
     ORDER BY b.Date_creation DESC"
);
$stmt->execute([$respId]);
$besoins = $stmt->fetchAll();

$totalFinancier = 0;
foreach ($besoins as $b) { $totalFinancier += (int)$b['Estimation_financiere']; }

include __DIR__ . '/../includes/header.php';
?>
<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="stat-card">
            <div class="stat-icon" style="background:#e7edf5;color:var(--pta-navy)"><i class="bi bi-clipboard-check"></i></div>
            <div><div class="stat-value"><?= count($besoins) ?></div><div class="stat-label">Besoins exprimés</div></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <div class="stat-icon" style="background:#fff3e6;color:var(--pta-orange)"><i class="bi bi-cash-coin"></i></div>
            <div><div class="stat-value"><?= number_format($totalFinancier, 0, ',', ' ') ?></div><div class="stat-label">Estimation totale (FCFA)</div></div>
        </div>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table mb-0">
            <thead><tr><th>Besoin</th><th>Type</th><th>Profil recherché</th><th>Estimation</th><th>Tâche</th><th>Agent</th><th>Date</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($besoins as $b): ?>
                <tr>
                    <td><?= h($b['libelle']) ?></td>
                    <td><span class="badge bg-light text-dark border"><?= h(ucfirst($b['Type'])) ?></span></td>
                    <td><?= $b['Type'] === 'humain' ? (!empty($b['profil_humain']) ? h($b['profil_humain']) : '<span class="text-muted">non précisé</span>') : '—' ?></td>
                    <td><?= number_format($b['Estimation_financiere'], 0, ',', ' ') ?> FCFA</td>
                    <td><?= h($b['tache_libelle']) ?></td>
                    <td><?= h($b['Prenom'] . ' ' . $b['Nom']) ?></td>
                    <td><?= formatDate($b['Date_creation']) ?></td>
                    <td><a href="<?= BASE_URL ?>/tache_detail.php?id=<?= $b['ID__activites'] ?>#besoins" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($besoins)): ?><tr><td colspan="8" class="text-center text-muted py-4">Aucun besoin exprimé pour le moment.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
