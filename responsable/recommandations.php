<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = requireRole(['responsable']);
$pageTitle = 'Recommandations reçues';
$moi = (int)$user['ID_agent_dpip'];

// Supérieur hiérarchique (Directeur) : affiché en en-tête. Le titre du compte n'est jamais modifié.
$s = $pdo->prepare(
    'SELECT s.Nom, s.Prenom, s.role FROM agent_dpip m JOIN agent_dpip s ON s.ID_agent_dpip = m.ID_responsable WHERE m.ID_agent_dpip = ?'
);
$s->execute([$moi]);
$sup = $s->fetch() ?: null;

$stmt = $pdo->prepare(
    "SELECT r.*, e.Nom, e.Prenom, e.role
     FROM recommandation r JOIN agent_dpip e ON e.ID_agent_dpip = r.ID_emetteur
     WHERE r.ID_destinataire = ? ORDER BY r.lu ASC, r.Date_creation DESC, r.id_recommandation DESC LIMIT 200"
);
$stmt->execute([$moi]);
$recos = $stmt->fetchAll();
$nonLues = count(array_filter($recos, fn($r) => !$r['lu']));

// Ouvrir la page = lire : les recommandations affichées sont marquées lues (le badge « Nouvelle » reste visible cette fois-ci).
if ($nonLues > 0) {
    $pdo->prepare('UPDATE recommandation SET lu = 1, date_lecture = NOW() WHERE ID_destinataire = ? AND lu = 0')->execute([$moi]);
}

include __DIR__ . '/../includes/header.php';
?>
<p class="text-muted">
    Recommandations de votre supérieur hiérarchique<?= $sup ? ' : <strong>' . h($sup['Prenom'] . ' ' . $sup['Nom']) . '</strong> (' . h(roleLabel($sup['role'])) . ')' : '' ?>.
    Vous gardez votre titre de <strong><?= h(roleLabelCompte($user)) ?></strong>.
</p>

<?php foreach ($recos as $r): ?>
    <div class="card mb-2 <?= !$r['lu'] ? 'border-primary' : '' ?>">
        <div class="card-body">
            <div class="d-flex justify-content-between flex-wrap gap-2">
                <div>
                    <strong><?= h($r['objet']) ?></strong>
                    <span class="badge <?= importanceRecommandationBadge($r['importance']) ?> ms-1"><?= h(importanceRecommandationLabel($r['importance'])) ?></span>
                    <?php if (!$r['lu']): ?><span class="badge bg-primary">Nouvelle</span><?php endif; ?>
                </div>
                <span class="text-muted small"><?= formatDate($r['Date_creation'], true) ?></span>
            </div>
            <div class="small text-muted mb-1">De <?= h($r['Prenom'] . ' ' . $r['Nom']) ?> (<?= h(roleLabel($r['role'])) ?>)</div>
            <p class="mb-0"><?= nl2br(h($r['message'])) ?></p>
        </div>
    </div>
<?php endforeach; ?>
<?php if (empty($recos)): ?><div class="card"><div class="card-body text-center text-muted py-5">Aucune recommandation reçue pour le moment.</div></div><?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
