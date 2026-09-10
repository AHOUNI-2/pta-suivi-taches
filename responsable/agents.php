<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = requireRole(['responsable']);
$pageTitle = 'Mes agents';
$respId = (int)$user['ID_agent_dpip'];

$stmt = $pdo->prepare(
    "SELECT ag.*, d.Nom_court AS dept_nom, ag.Profil AS profils,
            COUNT(a.ID__activites) AS total_taches,
            SUM(a.Statut='termine') AS termine,
            SUM(a.Statut='en_cours') AS en_cours,
            SUM(a.Statut='en_retard') AS en_retard
     FROM agent_dpip ag
     LEFT JOIN departement d ON d.id_departement = ag.ID_departement
     LEFT JOIN activites a ON a.ID_agent_dpip = ag.ID_agent_dpip
     WHERE ag.ID_responsable = ?
     GROUP BY ag.ID_agent_dpip ORDER BY ag.Nom"
);
$stmt->execute([$respId]);
$agents = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<p class="text-muted">Liste des agents placés sous votre responsabilité. Pour ajouter un nouvel agent, contactez l'administrateur.</p>

<div class="row g-3">
    <?php foreach ($agents as $a): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <span class="avatar-circle" style="width:48px;height:48px;">
                            <?php if (!empty($a['photo'])): ?>
                                <img src="<?= UPLOAD_URL_AVATARS . h($a['photo']) ?>" alt="">
                            <?php else: ?>
                                <?= h(mb_substr($a['Prenom'], 0, 1) . mb_substr($a['Nom'], 0, 1)) ?>
                            <?php endif; ?>
                        </span>
                        <div>
                            <div class="fw-semibold"><?= h($a['Prenom'] . ' ' . $a['Nom']) ?></div>
                            <div class="text-muted small"><?= h($a['dept_nom'] ?? '') ?></div>
                        </div>
                        <?php if ($a['statut_compte'] !== 'actif'): ?><span class="badge <?= statutCompteBadgeClass($a['statut_compte']) ?> ms-auto"><?= h(statutCompteLabel($a['statut_compte'])) ?></span><?php endif; ?>
                    </div>
                    <?php if (!empty($a['profils'])): ?>
                        <div class="small text-muted mb-2"><i class="bi bi-mortarboard"></i> <?= h($a['profils']) ?></div>
                    <?php endif; ?>
                    <div class="row text-center small g-0 mb-3">
                        <div class="col"><div class="fw-bold"><?= (int)$a['total_taches'] ?></div><div class="text-muted">Total</div></div>
                        <div class="col"><div class="fw-bold text-primary"><?= (int)$a['en_cours'] ?></div><div class="text-muted">En cours</div></div>
                        <div class="col"><div class="fw-bold text-success"><?= (int)$a['termine'] ?></div><div class="text-muted">Terminées</div></div>
                        <div class="col"><div class="fw-bold text-danger"><?= (int)$a['en_retard'] ?></div><div class="text-muted">En retard</div></div>
                    </div>
                    <a href="<?= BASE_URL ?>/responsable/activites.php?agent=<?= $a['ID_agent_dpip'] ?>" class="btn btn-sm btn-outline-secondary w-100">Voir ses tâches</a>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (empty($agents)): ?>
        <div class="col-12"><div class="card"><div class="card-body text-center text-muted py-5">Aucun agent ne vous est rattaché pour le moment. Contactez l'administrateur pour en ajouter.</div></div></div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
