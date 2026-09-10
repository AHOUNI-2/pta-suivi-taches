<?php
/**
 * MODÈLE de configuration — À COPIER en `config/config.php` puis à adapter.
 *   cp config/config.example.php config/config.php
 * `config/config.php` n'est PAS versionné (voir .gitignore) : chaque poste /
 * chaque hébergement a le sien.
 */

// --- Fuseau horaire ---
date_default_timezone_set('Africa/Abidjan');

// --- Rapport d'erreurs (mettre à 0 en PRODUCTION) ---
error_reporting(E_ALL);
ini_set('display_errors', '1');

// --- Session ---
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        // 'secure' => true,   // à activer si le site est en HTTPS
    ]);
    session_start();
}

// --- Connexion base de données (à adapter) ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'pta_bd_suivi');
define('DB_USER', 'pta_user');
define('DB_PASS', 'CHANGER_CE_MOT_DE_PASSE');
define('DB_CHARSET', 'utf8mb4');

// --- Chemin d'accès dans le navigateur ---
//   '' si l'application est à la racine du domaine  (https://exemple.com/)
//   '/pta_suivi_taches' si servie dans un sous-dossier
define('BASE_URL', '/MON_PROJETS_PTA/pta_suivi_taches');

define('ROOT_PATH', dirname(__DIR__));
define('UPLOAD_PATH_LIVRABLES', ROOT_PATH . '/uploads/livrables/');
define('UPLOAD_PATH_COMPTES_RENDUS', ROOT_PATH . '/uploads/comptes_rendus/');
define('UPLOAD_PATH_AVATARS', ROOT_PATH . '/uploads/avatars/');

define('UPLOAD_URL_LIVRABLES', BASE_URL . '/uploads/livrables/');
define('UPLOAD_URL_COMPTES_RENDUS', BASE_URL . '/uploads/comptes_rendus/');
define('UPLOAD_URL_AVATARS', BASE_URL . '/uploads/avatars/');

define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10 Mo
define('ALLOWED_UPLOAD_EXT', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'zip', 'rar', 'txt', 'csv']);

// --- Nom de l'application ---
define('APP_NAME', 'PTA - Suivi des Tâches');
define('APP_SIGLE', 'DPIP');

// --- Notifications par email ---
define('MAIL_ENABLED', false);
define('MAIL_METHOD', 'mail');            // 'mail' ou 'smtp'
define('MAIL_FROM', 'no-reply@pta.local');
define('MAIL_FROM_NAME', APP_NAME);

// Paramètres SMTP (si MAIL_METHOD = 'smtp')
define('SMTP_HOST', 'smtp.example.com');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls');
define('SMTP_USER', 'user@example.com');
define('SMTP_PASS', 'motdepasse');
