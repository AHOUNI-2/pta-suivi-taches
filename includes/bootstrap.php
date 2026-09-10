<?php
/**
 * Point d'entrée commun : à inclure en tête de chaque page.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';

$pdo = Database::getConnection();
