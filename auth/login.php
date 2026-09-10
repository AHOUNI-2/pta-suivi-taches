<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (isLoggedIn()) {
    redirect(dashboardUrlForRole(currentUser()['role']));
}

$errors = [];
$identifiant = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifiant = post('identifiant');
    $motDePasse = post('mot_de_passe');

    if ($identifiant === '' || $motDePasse === '') {
        $errors[] = 'Veuillez renseigner votre identifiant et votre mot de passe.';
    } else {
        $user = attemptLogin($pdo, $identifiant, $motDePasse);
        if ($user) {
            redirect(dashboardUrlForRole($user['role']));
        }
        $chk = $pdo->prepare('SELECT statut_compte FROM agent_dpip WHERE Matricule = ? OR Mail = ? LIMIT 1');
        $chk->execute([$identifiant, $identifiant]);
        $statut = $chk->fetchColumn();
        if ($statut === 'non_active') {
            $errors[] = "Votre compte n'a pas encore été activé par l'administrateur. Réessayez une fois qu'il vous aura confirmé l'activation.";
        } elseif ($statut === 'en_attente') {
            $errors[] = "Votre compte est activé mais votre mot de passe n'est pas défini. Utilisez « Première connexion » pour le choisir.";
        } else {
            $errors[] = 'Identifiant ou mot de passe incorrect, ou compte désactivé.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Connexion — <?= h(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/vendor/bootstrap/css/bootstrap.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/vendor/bootstrap-icons/font/bootstrap-icons.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<div class="login-wrapper">
    <div class="login-card">
        <div class="row g-0">
            <div class="col-md-5 login-side">
                <div class="brand-icon-lg"><?= h(APP_SIGLE) ?></div>
                <h2 class="h4 fw-bold mb-2"><?= h(APP_NAME) ?></h2>
                <p class="text-white-50 mb-0">Plateforme de gestion et de suivi des tâches : attribution par les responsables, exécution et compte rendu par les agents.</p>
            </div>
            <div class="col-md-7">
                <div class="p-4 p-md-5">

                    <?php foreach (getFlashMessages() as $msg): ?>
                        <div class="alert alert-<?= h($msg['type']) ?> py-2"><?= h($msg['message']) ?></div>
                    <?php endforeach; ?>
                    <?php foreach ($errors as $err): ?>
                        <div class="alert alert-danger py-2"><?= h($err) ?></div>
                    <?php endforeach; ?>

                    <h3 class="h5 fw-bold mb-1">Connexion à votre espace</h3>
                    <p class="text-muted small mb-4">Agent, responsable ou administrateur : connectez-vous avec votre matricule (ou email) et votre mot de passe.</p>

                    <form method="post" novalidate>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Matricule ou email</label>
                            <input type="text" name="identifiant" class="form-control" value="<?= h($identifiant) ?>" required autofocus>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Mot de passe</label>
                            <input type="password" name="mot_de_passe" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-lg w-100 text-white" style="background:var(--pta-navy)">
                            <i class="bi bi-box-arrow-in-right me-1"></i> Se connecter
                        </button>
                    </form>
                    <p class="text-muted small mt-4 mb-1">
                        Nouveau compte ? <a href="<?= BASE_URL ?>/auth/activation.php" class="text-decoration-none fw-semibold">Première connexion — définir mon mot de passe</a>
                    </p>
                    <p class="text-muted small mb-0">Mot de passe oublié ? Contactez votre administrateur.</p>

                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
