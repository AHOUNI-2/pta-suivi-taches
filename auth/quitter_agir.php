<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$me = requireLogin();

stopActingForDirecteur();
flash('success', 'Vous êtes revenu à votre espace personnel.');
redirect(dashboardUrlForRole($me['role']));
