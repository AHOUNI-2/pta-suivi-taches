<?php
require_once __DIR__ . '/includes/bootstrap.php';
$user = requireLogin();

// Endpoint AJAX léger pour marquer une notification comme lue au clic
if (isset($_GET['ajax']) && $_GET['ajax'] === 'mark_read') {
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if ($id > 0) {
        $pdo->prepare('UPDATE notifications SET Lu = 1 WHERE id_notification = ? AND ID_agent_dpip = ?')
            ->execute([$id, $user['ID_agent_dpip']]);
    }
    exit('ok');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf() && post('action') === 'mark_all_read') {
    $pdo->prepare('UPDATE notifications SET Lu = 1 WHERE ID_agent_dpip = ?')->execute([$user['ID_agent_dpip']]);
    flash('success', 'Toutes les notifications ont été marquées comme lues.');
    redirect('/notifications.php');
}

$pageTitle = 'Notifications';
$stmt = $pdo->prepare('SELECT * FROM notifications WHERE ID_agent_dpip = ? ORDER BY Date_creation DESC LIMIT 100');
$stmt->execute([$user['ID_agent_dpip']]);
$notifications = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Toutes mes notifications</span>
        <form method="post" class="mb-0">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="mark_all_read">
            <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-check2-all"></i> Tout marquer comme lu</button>
        </form>
    </div>
    <div class="list-group list-group-flush">
        <?php if (empty($notifications)): ?>
            <div class="p-4 text-center text-muted">Vous n'avez aucune notification pour le moment.</div>
        <?php endif; ?>
        <?php foreach ($notifications as $n): ?>
            <a href="<?= BASE_URL ?><?= h($n['Lien'] ?: '#') ?>" data-notif-id="<?= $n['id_notification'] ?>" class="list-group-item list-group-item-action <?= !$n['Lu'] ? 'bg-light' : '' ?>">
                <div class="d-flex justify-content-between">
                    <span class="fw-semibold"><?= !$n['Lu'] ? '<i class="bi bi-circle-fill text-primary" style="font-size:.5rem;"></i> ' : '' ?><?= h($n['Titre']) ?></span>
                    <span class="text-muted small"><?= timeAgo($n['Date_creation']) ?></span>
                </div>
                <div class="text-muted small"><?= h($n['Message']) ?></div>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
