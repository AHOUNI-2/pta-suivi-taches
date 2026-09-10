<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$me = requireLogin();

$bossId = (int)get('directeur');

if (startActingForDirecteur($pdo, $bossId)) {
    flash('success', "Vous agissez désormais au nom du Directeur. Toutes vos actions restent enregistrées à votre nom.");
    redirect('/directeur/dashboard.php');
}

flash('danger', "Vous n'êtes pas la secrétaire désignée de ce directeur.");
redirect(dashboardUrlForRole($me['role']));
