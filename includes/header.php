<?php
/**
 * En-tête commun des pages authentifiées.
 * Attend éventuellement $pageTitle défini avant l'inclusion.
 */
$user = currentUser();
$unreadNotifCount = $user ? countUnreadNotifications($pdo, (int)$user['ID_agent_dpip']) : 0;
$pageTitle = $pageTitle ?? 'Tableau de bord';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($pageTitle) ?> — <?= h(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/vendor/bootstrap/css/bootstrap.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/vendor/bootstrap-icons/font/bootstrap-icons.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= @filemtime(ROOT_PATH . "/assets/css/style.css") ?>">
<script>window.BASE_URL = "<?= BASE_URL ?>";</script>
</head>
<body>
<div class="app-wrapper">
    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="app-main">
        <header class="app-topbar">
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-light border sidebar-toggle-btn" type="button" aria-label="Menu">
                    <i class="bi bi-list"></i>
                </button>
                <h1 class="h5 mb-0 text-navy" style="color:var(--pta-navy)"><?= h($pageTitle) ?></h1>
            </div>
            <div class="topbar-user">
                <div class="dropdown">
                    <button class="btn btn-light border position-relative" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-bell"></i>
                        <?php if ($unreadNotifCount > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.6rem;"><?= $unreadNotifCount ?></span>
                        <?php endif; ?>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end notif-dropdown p-0">
                        <div class="p-2 border-bottom fw-semibold small text-uppercase text-muted">Notifications</div>
                        <?php
                        $stmtNotif = $pdo->prepare('SELECT * FROM notifications WHERE ID_agent_dpip = ? ORDER BY Date_creation DESC LIMIT 6');
                        $stmtNotif->execute([$user['ID_agent_dpip']]);
                        $notifs = $stmtNotif->fetchAll();
                        ?>
                        <?php if (empty($notifs)): ?>
                            <div class="notif-item text-muted text-center py-3">Aucune notification</div>
                        <?php endif; ?>
                        <?php foreach ($notifs as $n): ?>
                            <a href="<?= BASE_URL ?><?= h($n['Lien'] ?: '/notifications.php') ?>" class="d-block text-decoration-none text-dark notif-item <?= !$n['Lu'] ? 'unread' : '' ?>" data-notif-id="<?= $n['id_notification'] ?>">
                                <div class="fw-semibold"><?= h($n['Titre']) ?></div>
                                <div class="text-muted small"><?= h(mb_strimwidth($n['Message'], 0, 80, '…')) ?></div>
                                <div class="text-muted" style="font-size:.72rem;"><?= timeAgo($n['Date_creation']) ?></div>
                            </a>
                        <?php endforeach; ?>
                        <a href="<?= BASE_URL ?>/notifications.php" class="d-block text-center py-2 small border-top">Voir toutes les notifications</a>
                    </div>
                </div>
                <div class="dropdown">
                    <button class="btn d-flex align-items-center gap-2 border-0 bg-transparent" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="avatar-circle">
                            <?php if (!empty($user['photo'])): ?>
                                <img src="<?= UPLOAD_URL_AVATARS . h($user['photo']) ?>" alt="">
                            <?php else: ?>
                                <?= h(mb_substr($user['Prenom'], 0, 1) . mb_substr($user['Nom'], 0, 1)) ?>
                            <?php endif; ?>
                        </span>
                        <span class="d-none d-md-block text-start">
                            <span class="d-block small fw-semibold"><?= h($user['Prenom'] . ' ' . $user['Nom']) ?></span>
                            <span class="d-block text-muted" style="font-size:.72rem;"><?= h(roleLabel($user['role'])) ?></span>
                        </span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/profil.php"><i class="bi bi-person-circle me-2"></i>Mon profil</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="<?= BASE_URL ?>/auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Déconnexion</a></li>
                    </ul>
                </div>
            </div>
        </header>

        <main class="app-content">
            <?php $hdrBoss = function_exists('actingDirecteur') ? actingDirecteur($pdo) : null; ?>
            <?php if ($hdrBoss): ?>
                <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2" role="alert">
                    <span><i class="bi bi-person-badge"></i> Vous agissez au nom de <strong><?= h($hdrBoss['Prenom'] . ' ' . $hdrBoss['Nom']) ?></strong> (Directeur). Vos actions sont enregistrées à votre nom.</span>
                    <a href="<?= BASE_URL ?>/auth/quitter_agir.php" class="btn btn-sm btn-outline-dark">Quitter ce mode</a>
                </div>
            <?php endif; ?>
            <?php foreach (getFlashMessages() as $msg): ?>
                <div class="alert alert-<?= h($msg['type']) ?> alert-dismissible fade show" role="alert" data-autohide>
                    <?= h($msg['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endforeach; ?>
