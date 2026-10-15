<?php
/**
 * Authentification et contrôle d'accès par rôle
 */

/** Retourne l'utilisateur connecté (tableau associatif) ou null */
function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

function isLoggedIn(): bool
{
    return isset($_SESSION['user']);
}

/** Bloque l'accès si l'utilisateur n'est pas connecté */
function requireLogin(): array
{
    if (!isLoggedIn()) {
        redirect('/auth/login.php');
    }
    return currentUser();
}

/** Bloque l'accès si l'utilisateur n'a pas l'un des rôles autorisés */
function requireRole(array $roles): array
{
    $user = requireLogin();
    // Un Responsable Service ayant la fonction « Administrateur » accède aussi aux pages réservées aux admins.
    $autorise = in_array($user['role'], $roles, true) || (in_array('admin', $roles, true) && estAdministrateurActif($user));
    if (!$autorise) {
        http_response_code(403);
        flash('danger', "Vous n'avez pas accès à cette page.");
        redirect(dashboardUrlForRole($user['role']));
    }
    return $user;
}

/** URL du tableau de bord correspondant au rôle */
function dashboardUrlForRole(string $role): string
{
    return match ($role) {
        'admin' => '/admin/dashboard.php',
        'directeur' => '/directeur/dashboard.php',
        'responsable' => '/responsable/dashboard.php',
        'agent' => '/agent/dashboard.php',
        default => '/auth/login.php',
    };
}

/** Compte réellement connecté (jamais impacté par la délégation « agir pour »). */
function realUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

/**
 * Directeur pour lequel l'utilisateur connecté agit actuellement (délégation
 * secrétaire), ou null. Vérifie à chaque appel que la délégation est toujours
 * valide (la secrétaire est bien celle désignée par ce directeur).
 */
function actingDirecteur(PDO $pdo): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $me = realUser();
    $bossId = isset($_SESSION['agir_pour']) ? (int)$_SESSION['agir_pour'] : 0;
    if (!$me || !$bossId) {
        return $cache = null;
    }
    $stmt = $pdo->prepare(
        "SELECT a.*, d.Libelle AS Departement_libelle, d.Nom_court AS Departement_nom
         FROM agent_dpip a LEFT JOIN departement d ON d.id_departement = a.ID_departement
         WHERE a.ID_agent_dpip = ? AND a.role = 'directeur' AND a.ID_secretaire = ? LIMIT 1"
    );
    $stmt->execute([$bossId, $me['ID_agent_dpip']]);
    $boss = $stmt->fetch();
    return $cache = ($boss ?: null);
}

/** Démarre la délégation : la secrétaire connectée agit pour le directeur $bossId. */
function startActingForDirecteur(PDO $pdo, int $bossId): bool
{
    $me = realUser();
    if (!$me) {
        return false;
    }
    $stmt = $pdo->prepare("SELECT 1 FROM agent_dpip WHERE ID_agent_dpip = ? AND role = 'directeur' AND ID_secretaire = ? AND statut_compte = 'actif'");
    $stmt->execute([$bossId, $me['ID_agent_dpip']]);
    if (!$stmt->fetchColumn()) {
        return false;
    }
    $_SESSION['agir_pour'] = $bossId;
    return true;
}

/** Arrête la délégation en cours. */
function stopActingForDirecteur(): void
{
    unset($_SESSION['agir_pour']);
}

/**
 * Contexte « Direction » d'une page de l'espace directeur :
 *  - l'utilisateur connecté s'il est directeur ;
 *  - sinon le directeur pour lequel la secrétaire connectée agit.
 * Redirige (403) si l'accès n'est pas légitime.
 */
function requireDirecteurAccess(PDO $pdo): array
{
    $me = requireLogin();
    if ($me['role'] === 'directeur') {
        return $me;
    }
    $boss = actingDirecteur($pdo);
    if ($boss) {
        return $boss;
    }
    http_response_code(403);
    flash('danger', "Cet espace est réservé à la direction.");
    redirect(dashboardUrlForRole($me['role']));
}

/**
 * Tente de connecter un utilisateur via son matricule ou son email + mot de passe.
 * Retourne le tableau utilisateur en cas de succès, ou null en cas d'échec.
 */
function attemptLogin(PDO $pdo, string $identifiant, string $motDePasse): ?array
{
    $stmt = $pdo->prepare(
        'SELECT a.*, d.Libelle AS Departement_libelle, d.Nom_court AS Departement_nom
         FROM agent_dpip a
         LEFT JOIN departement d ON d.id_departement = a.ID_departement
         WHERE (a.Matricule = ? OR a.Mail = ?) LIMIT 1'
    );
    $stmt->execute([$identifiant, $identifiant]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($motDePasse, $user['mot_de_passe'])) {
        return null;
    }
    if ($user['statut_compte'] !== 'actif') {
        return null;
    }

    unset($user['mot_de_passe']);
    $_SESSION['user'] = $user;

    $upd = $pdo->prepare('UPDATE agent_dpip SET derniere_connexion = NOW() WHERE ID_agent_dpip = ?');
    $upd->execute([$user['ID_agent_dpip']]);

    // Journal des connexions (sécurité) — ne doit jamais bloquer la connexion.
    try {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $ins = $pdo->prepare('INSERT INTO connexion (id_agent_dpip, adresse_ip, pays, user_agent) VALUES (?, ?, ?, ?)');
        $ins->execute([
            $user['ID_agent_dpip'], $ip, paysDepuisIp($ip),
            mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
        $_SESSION['id_connexion'] = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        // trace indisponible : on continue
    }

    return $user;
}

function logoutUser(?PDO $pdo = null): void
{
    if ($pdo instanceof PDO && !empty($_SESSION['id_connexion'])) {
        try {
            $pdo->prepare('UPDATE connexion SET date_heure_deconnexion = NOW() WHERE id_connexion = ? AND date_heure_deconnexion IS NULL')
                ->execute([(int)$_SESSION['id_connexion']]);
        } catch (Throwable $e) {
            // on déconnecte quand même
        }
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/**
 * Rafraîchit les données de l'utilisateur en session depuis la base
 * (utile après modification de son propre profil).
 */
function refreshCurrentUser(PDO $pdo): void
{
    if (!isLoggedIn()) {
        return;
    }
    $stmt = $pdo->prepare(
        'SELECT a.*, d.Libelle AS Departement_libelle, d.Nom_court AS Departement_nom
         FROM agent_dpip a
         LEFT JOIN departement d ON d.id_departement = a.ID_departement
         WHERE a.ID_agent_dpip = ? LIMIT 1'
    );
    $stmt->execute([$_SESSION['user']['ID_agent_dpip']]);
    $user = $stmt->fetch();
    if ($user) {
        unset($user['mot_de_passe']);
        $_SESSION['user'] = $user;
    }
}
