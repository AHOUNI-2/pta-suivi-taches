<?php
// Conservé pour compatibilité : la page est désormais commune à tous les rôles.
require_once __DIR__ . '/../includes/bootstrap.php';
requireLogin();
redirect('/activites_recues.php');
