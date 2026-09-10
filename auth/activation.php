<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (isLoggedIn()) {
    redirect(dashboardUrlForRole(currentUser()['role']));
}

$errors = [];
$matricule = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $matricule = post('matricule');
    $mdp = post('mot_de_passe');
    $confirmation = post('confirmation');

    if ($matricule === '' || $mdp === '') {
        $errors[] = 'Le matricule et le mot de passe sont obligatoires.';
    } elseif (strlen($mdp) < 6) {
        $errors[] = 'Le mot de passe doit contenir au moins 6 caractères.';
    } elseif ($mdp !== $confirmation) {
        $errors[] = 'La confirmation ne correspond pas au mot de passe.';
    } else {
        $stmt = $pdo->prepare(
            "SELECT ID_agent_dpip FROM agent_dpip WHERE Matricule = ? AND statut_compte = 'en_attente' LIMIT 1"
        );
        $stmt->execute([$matricule]);
        $compte = $stmt->fetch();

        if (!$compte) {
            $stmtEtat = $pdo->prepare('SELECT statut_compte FROM agent_dpip WHERE Matricule = ? LIMIT 1');
            $stmtEtat->execute([$matricule]);
            $etat = $stmtEtat->fetchColumn();
            if ($etat === 'non_active') {
                $errors[] = "Votre compte n'a pas encore été activé par l'administrateur. Vous pourrez définir votre mot de passe une fois l'activation faite.";
            } elseif ($etat === 'actif') {
                $errors[] = "Votre mot de passe est déjà défini : connectez-vous directement.";
            } else {
                $errors[] = "Aucun compte à activer ne correspond à ce matricule. Vérifiez auprès de votre administrateur.";
            }
        } else {
            $pdo->prepare(
                "UPDATE agent_dpip SET mot_de_passe = ?, statut_compte = 'actif' WHERE ID_agent_dpip = ?"
            )->execute([password_hash($mdp, PASSWORD_BCRYPT), $compte['ID_agent_dpip']]);

            flash('success', 'Votre mot de passe est défini. Connectez-vous avec votre matricule et votre nouveau mot de passe.');
            redirect('/auth/login.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Première connexion — <?= h(APP_NAME) ?></title>
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
                <p class="text-white-50 mb-0">Première connexion : définissez votre mot de passe pour accéder à votre espace.</p>
            </div>
            <div class="col-md-7">
                <div class="p-4 p-md-5">
                    <h3 class="h5 fw-bold mb-1">Définir mon mot de passe</h3>
                    <p class="text-muted small mb-4">Renseignez votre matricule, puis choisissez votre mot de passe.</p>

                    <?php foreach ($errors as $err): ?>
                        <div class="alert alert-danger py-2"><?= h($err) ?></div>
                    <?php endforeach; ?>

                    <form method="post" novalidate>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Matricule</label>
                            <input type="text" name="matricule" class="form-control" value="<?= h($matricule) ?>" required autofocus>
                        </div>
                        <div class="row g-2">
                            <div class="col-sm-6 mb-3">
                                <label class="form-label small fw-semibold">Nouveau mot de passe</label>
                                <input type="password" name="mot_de_passe" class="form-control" minlength="6" required>
                            </div>
                            <div class="col-sm-6 mb-3">
                                <label class="form-label small fw-semibold">Confirmer le mot de passe</label>
                                <input type="password" name="confirmation" class="form-control" minlength="6" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-lg w-100 text-white" style="background:var(--pta-navy)">
                            <i class="bi bi-shield-check me-1"></i> Définir mon mot de passe
                        </button>
                    </form>
                    <p class="text-muted small mt-4 mb-0">
                        <a href="<?= BASE_URL ?>/auth/login.php" class="text-decoration-none"><i class="bi bi-arrow-left"></i> Retour à la connexion</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
