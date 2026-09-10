<?php
/**
 * Barre latérale de navigation, dépend du rôle de l'utilisateur connecté.
 * Attend que $user (utilisateur courant) soit disponible.
 */
$currentScript = basename($_SERVER['SCRIPT_NAME']);
$currentDir = basename(dirname($_SERVER['SCRIPT_NAME']));

function navActive(string $dir, string $script, string $curDir, string $curScript): string
{
    return ($dir === $curDir && $script === $curScript) ? 'active' : '';
}

// Contexte de navigation : une secrétaire qui « agit pour » un directeur voit le menu Direction.
$actingBoss = function_exists('actingDirecteur') ? actingDirecteur($pdo) : null;
$navRole = $actingBoss ? 'directeur' : $user['role'];

// L'utilisateur connecté est-il la secrétaire désignée d'un directeur (et n'agit pas déjà pour lui) ?
$monDirecteur = null;
if (!$actingBoss && in_array($user['role'], ['agent', 'responsable'], true)) {
    $stmtSec = $pdo->prepare("SELECT ID_agent_dpip, Nom, Prenom FROM agent_dpip WHERE role = 'directeur' AND ID_secretaire = ? AND statut_compte = 'actif' LIMIT 1");
    $stmtSec->execute([$user['ID_agent_dpip']]);
    $monDirecteur = $stmtSec->fetch() ?: null;
}
?>
<aside class="app-sidebar">
    <div class="brand">
        <div class="brand-icon"><?= h(APP_SIGLE) ?></div>
        <div class="brand-text"><?= h(APP_NAME) ?><small>Ministère du Plan et du Développement</small></div>
    </div>

    <nav class="nav flex-column py-2">
        <?php if ($actingBoss): ?>
            <div class="px-3 py-2 mb-1 small" style="background:var(--pta-orange);color:#fff;border-radius:8px;margin:0 .5rem;">
                <i class="bi bi-person-badge"></i> Vous agissez pour le<br><strong><?= h($actingBoss['Prenom'] . ' ' . $actingBoss['Nom']) ?></strong> (Directeur)
                <a href="<?= BASE_URL ?>/auth/quitter_agir.php" class="d-block text-white text-decoration-underline mt-1">Quitter ce mode</a>
            </div>
        <?php elseif ($monDirecteur): ?>
            <a href="<?= BASE_URL ?>/auth/agir.php?directeur=<?= (int)$monDirecteur['ID_agent_dpip'] ?>" class="nav-link" style="color:var(--pta-orange);">
                <i class="bi bi-person-badge"></i> Agir pour le Directeur
            </a>
        <?php endif; ?>

        <?php if ($navRole === 'directeur'): ?>
            <div class="nav-section-title">Direction</div>
            <a href="<?= BASE_URL ?>/directeur/dashboard.php" class="nav-link <?= navActive('directeur', 'dashboard.php', $currentDir, $currentScript) ?>">
                <i class="bi bi-speedometer2"></i> Tableau de bord
            </a>
            <a href="<?= BASE_URL ?>/directeur/activites.php" class="nav-link <?= navActive('directeur', 'activites.php', $currentDir, $currentScript) ?>">
                <i class="bi bi-list-task"></i> Activités &amp; tâches
            </a>
            <a href="<?= BASE_URL ?>/directeur/activite_form.php" class="nav-link <?= navActive('directeur', 'activite_form.php', $currentDir, $currentScript) ?>">
                <i class="bi bi-plus-circle"></i> Déléguer une activité
            </a>
            <a href="<?= BASE_URL ?>/directeur/comptes_rendus.php" class="nav-link <?= navActive('directeur', 'comptes_rendus.php', $currentDir, $currentScript) ?>">
                <i class="bi bi-chat-square-text"></i> Comptes rendus
            </a>
        <?php elseif ($navRole === 'admin'): ?>
            <div class="nav-section-title">Pilotage</div>
            <a href="<?= BASE_URL ?>/admin/dashboard.php" class="nav-link <?= navActive('admin', 'dashboard.php', $currentDir, $currentScript) ?>">
                <i class="bi bi-speedometer2"></i> Tableau de bord
            </a>
            <a href="<?= BASE_URL ?>/admin/activites.php" class="nav-link <?= navActive('admin', 'activites.php', $currentDir, $currentScript) ?>">
                <i class="bi bi-list-task"></i> Activités &amp; tâches
            </a>
            <a href="<?= BASE_URL ?>/admin/activite_form.php" class="nav-link <?= navActive('admin', 'activite_form.php', $currentDir, $currentScript) ?>">
                <i class="bi bi-plus-circle"></i> Nouvelle activité
            </a>
            <a href="<?= BASE_URL ?>/admin/rapports.php" class="nav-link <?= navActive('admin', 'rapports.php', $currentDir, $currentScript) ?>">
                <i class="bi bi-bar-chart-line"></i> Rapports &amp; statistiques
            </a>
            <div class="nav-section-title">Administration</div>
            <a href="<?= BASE_URL ?>/admin/utilisateurs.php" class="nav-link <?= navActive('admin', 'utilisateurs.php', $currentDir, $currentScript) ?>">
                <i class="bi bi-people"></i> Utilisateurs
            </a>
            <a href="<?= BASE_URL ?>/admin/departements.php" class="nav-link <?= navActive('admin', 'departements.php', $currentDir, $currentScript) ?>">
                <i class="bi bi-diagram-3"></i> Départements
            </a>
            <a href="<?= BASE_URL ?>/admin/cause_reafectation.php" class="nav-link <?= navActive('admin', 'cause_reafectation.php', $currentDir, $currentScript) ?>">
                <i class="bi bi-arrow-left-right"></i> Causes de réaffectation
            </a>
            <a href="<?= BASE_URL ?>/admin/connexions.php" class="nav-link <?= navActive('admin', 'connexions.php', $currentDir, $currentScript) ?>">
                <i class="bi bi-shield-lock"></i> Journal des connexions
            </a>
            <a href="<?= BASE_URL ?>/admin/historique.php" class="nav-link <?= navActive('admin', 'historique.php', $currentDir, $currentScript) ?>">
                <i class="bi bi-clock-history"></i> Historique des modifications
            </a>
        <?php elseif ($navRole === 'responsable'): ?>
            <div class="nav-section-title">Pilotage</div>
            <a href="<?= BASE_URL ?>/responsable/dashboard.php" class="nav-link <?= navActive('responsable', 'dashboard.php', $currentDir, $currentScript) ?>">
                <i class="bi bi-speedometer2"></i> Tableau de bord
            </a>
            <a href="<?= BASE_URL ?>/responsable/activites.php" class="nav-link <?= navActive('responsable', 'activites.php', $currentDir, $currentScript) ?>">
                <i class="bi bi-list-task"></i> Activités &amp; tâches
            </a>
            <a href="<?= BASE_URL ?>/responsable/activite_form.php" class="nav-link <?= navActive('responsable', 'activite_form.php', $currentDir, $currentScript) ?>">
                <i class="bi bi-plus-circle"></i> Nouvelle activité
            </a>
            <a href="<?= BASE_URL ?>/activites_recues.php" class="nav-link <?= navActive('', 'activites_recues.php', $currentDir, $currentScript) ?>">
                <i class="bi bi-inbox"></i> Activités qui me sont confiées
            </a>
            <div class="nav-section-title">Suivi</div>
            <a href="<?= BASE_URL ?>/responsable/comptes_rendus.php" class="nav-link <?= navActive('responsable', 'comptes_rendus.php', $currentDir, $currentScript) ?>">
                <i class="bi bi-chat-square-text"></i> Comptes rendus
            </a>
            <a href="<?= BASE_URL ?>/responsable/besoins.php" class="nav-link <?= navActive('responsable', 'besoins.php', $currentDir, $currentScript) ?>">
                <i class="bi bi-clipboard-check"></i> Besoins exprimés
            </a>
            <a href="<?= BASE_URL ?>/responsable/agents.php" class="nav-link <?= navActive('responsable', 'agents.php', $currentDir, $currentScript) ?>">
                <i class="bi bi-people"></i> Mes agents
            </a>
        <?php else: ?>
            <div class="nav-section-title">Mon activité</div>
            <a href="<?= BASE_URL ?>/agent/dashboard.php" class="nav-link <?= navActive('agent', 'dashboard.php', $currentDir, $currentScript) ?>">
                <i class="bi bi-speedometer2"></i> Tableau de bord
            </a>
            <a href="<?= BASE_URL ?>/agent/mes_taches.php" class="nav-link <?= navActive('agent', 'mes_taches.php', $currentDir, $currentScript) ?>">
                <i class="bi bi-list-task"></i> Mes activités &amp; tâches
            </a>
            <a href="<?= BASE_URL ?>/agent/comptes_rendus.php" class="nav-link <?= navActive('agent', 'comptes_rendus.php', $currentDir, $currentScript) ?>">
                <i class="bi bi-chat-square-text"></i> Mes comptes rendus
            </a>
        <?php endif; ?>

        <?php if (!$actingBoss && $user['role'] !== 'agent' && $user['role'] !== 'responsable' && !empty($user['est_agent'])): ?>
            <a href="<?= BASE_URL ?>/activites_recues.php" class="nav-link <?= navActive('', 'activites_recues.php', $currentDir, $currentScript) ?>">
                <i class="bi bi-inbox"></i> Activités qui me sont confiées
            </a>
        <?php endif; ?>

        <div class="nav-section-title">Compte</div>
        <a href="<?= BASE_URL ?>/notifications.php" class="nav-link <?= navActive('', 'notifications.php', $currentDir, $currentScript) ?>">
            <i class="bi bi-bell"></i> Notifications
            <?php if (!empty($unreadNotifCount)): ?><span class="badge rounded-pill badge-count"><?= $unreadNotifCount ?></span><?php endif; ?>
        </a>
        <a href="<?= BASE_URL ?>/profil.php" class="nav-link <?= navActive('', 'profil.php', $currentDir, $currentScript) ?>">
            <i class="bi bi-person-circle"></i> Mon profil
        </a>
        <a href="<?= BASE_URL ?>/auth/logout.php" class="nav-link">
            <i class="bi bi-box-arrow-right"></i> Déconnexion
        </a>
    </nav>
</aside>
<div class="sidebar-backdrop"></div>
