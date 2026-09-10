<?php
require_once __DIR__ . '/includes/bootstrap.php';
$user = requireLogin();
$pageTitle = 'Mon profil';

$errors = [];
$success = null;

/** Supprime physiquement un fichier avatar (protégé contre le path traversal). */
function supprimerFichierAvatar(?string $nomFichier): void
{
    if (empty($nomFichier) || basename($nomFichier) !== $nomFichier) {
        return;
    }
    $chemin = UPLOAD_PATH_AVATARS . $nomFichier;
    if (is_file($chemin)) {
        @unlink($chemin);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = post('action');

    if ($action === 'update_secretaire' && $user['role'] === 'directeur') {
        $secId = post('ID_secretaire') !== '' ? (int)post('ID_secretaire') : null;
        try {
            if ($secId !== null) {
                $chk = $pdo->prepare("SELECT 1 FROM agent_dpip WHERE ID_agent_dpip = ? AND role IN ('agent','responsable') AND statut_compte = 'actif'");
                $chk->execute([$secId]);
                if (!$chk->fetchColumn()) {
                    throw new RuntimeException('Secrétaire invalide (compte agent ou responsable actif requis).');
                }
            }
            $pdo->prepare('UPDATE agent_dpip SET ID_secretaire = ? WHERE ID_agent_dpip = ?')
                ->execute([$secId, $user['ID_agent_dpip']]);
            refreshCurrentUser($pdo);
            logModif($pdo, 'agent_dpip', (int)$user['ID_agent_dpip'], 'delegation', $secId ? 'Secrétaire déléguée désignée (compte #' . $secId . ')' : 'Délégation à la secrétaire retirée');
            flash('success', $secId ? 'Secrétaire enregistrée. Elle pourra agir en votre nom pendant vos absences.' : 'Délégation à la secrétaire retirée.');
            redirect('/profil.php');
        } catch (Throwable $e) {
            $errors[] = 'Erreur : ' . $e->getMessage();
        }
    }

    if ($action === 'delete_photo') {
        try {
            supprimerFichierAvatar($user['photo'] ?? null);
            $pdo->prepare('UPDATE agent_dpip SET photo = NULL WHERE ID_agent_dpip = ?')
                ->execute([$user['ID_agent_dpip']]);
            refreshCurrentUser($pdo);
            flash('success', 'Votre photo de profil a été supprimée.');
        } catch (Throwable $e) {
            flash('danger', 'Erreur : ' . $e->getMessage());
        }
        redirect('/profil.php');
    }

    if ($action === 'update_info') {
        $mail = post('Mail');
        $mailPro = post('Mail_Pro');
        $contact = post('Contact');
        $profil = post('Profil');

        if ($mail === '' || !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Veuillez saisir un email personnel valide.';
        }

        if (!$errors) {
            try {
                $stmt = $pdo->prepare('UPDATE agent_dpip SET Mail = ?, Mail_Pro = ?, Contact = ?, Profil = ? WHERE ID_agent_dpip = ?');
                $stmt->execute([$mail, $mailPro ?: null, $contact ?: null, $profil ?: null, $user['ID_agent_dpip']]);

                if (!empty($_FILES['photo']['name'])) {
                    $uploaded = handleFileUpload($_FILES['photo'], UPLOAD_PATH_AVATARS, 'avatar_' . $user['ID_agent_dpip']);
                    if ($uploaded) {
                        supprimerFichierAvatar($user['photo'] ?? null); // retire l'ancienne photo
                        $pdo->prepare('UPDATE agent_dpip SET photo = ? WHERE ID_agent_dpip = ?')
                            ->execute([$uploaded['stored_name'], $user['ID_agent_dpip']]);
                    }
                }
                refreshCurrentUser($pdo);
                logModif($pdo, 'agent_dpip', (int)$user['ID_agent_dpip'], 'modification', 'A modifié ses informations personnelles (email / contact / profil / photo)');
                flash('success', 'Votre profil a été mis à jour.');
                redirect('/profil.php');
            } catch (Throwable $e) {
                $errors[] = 'Erreur : ' . $e->getMessage();
            }
        }
    } elseif ($action === 'update_password') {
        $ancien = post('ancien_mot_de_passe');
        $nouveau = post('nouveau_mot_de_passe');
        $confirmation = post('confirmation_mot_de_passe');

        $stmt = $pdo->prepare('SELECT mot_de_passe FROM agent_dpip WHERE ID_agent_dpip = ?');
        $stmt->execute([$user['ID_agent_dpip']]);
        $hash = $stmt->fetchColumn();

        if (!password_verify($ancien, $hash)) {
            $errors[] = 'Le mot de passe actuel est incorrect.';
        } elseif (strlen($nouveau) < 6) {
            $errors[] = 'Le nouveau mot de passe doit contenir au moins 6 caractères.';
        } elseif ($nouveau !== $confirmation) {
            $errors[] = 'La confirmation ne correspond pas au nouveau mot de passe.';
        } else {
            $pdo->prepare('UPDATE agent_dpip SET mot_de_passe = ? WHERE ID_agent_dpip = ?')
                ->execute([password_hash($nouveau, PASSWORD_BCRYPT), $user['ID_agent_dpip']]);
            logModif($pdo, 'agent_dpip', (int)$user['ID_agent_dpip'], 'changement_mdp', 'A changé son mot de passe');
            flash('success', 'Votre mot de passe a été modifié.');
            redirect('/profil.php');
        }
    }
}

include __DIR__ . '/includes/header.php';
?>
<div class="row g-4">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-body text-center">
                <span class="avatar-circle mx-auto mb-2" style="width:80px;height:80px;font-size:1.6rem;">
                    <?php if (!empty($user['photo'])): ?>
                        <img src="<?= UPLOAD_URL_AVATARS . h($user['photo']) ?>" alt="">
                    <?php else: ?>
                        <?= h(mb_substr($user['Prenom'], 0, 1) . mb_substr($user['Nom'], 0, 1)) ?>
                    <?php endif; ?>
                </span>
                <?php if (!empty($user['photo'])): ?>
                    <div class="mb-2">
                        <form method="post" class="d-inline" data-confirm="Supprimer votre photo de profil ?">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_photo">
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="bi bi-trash"></i> Supprimer la photo
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
                <h5 class="mb-0"><?= h($user['Prenom'] . ' ' . $user['Nom']) ?></h5>
                <p class="text-muted small mb-2"><?= h(roleLabel($user['role'])) ?></p>
                <span class="badge bg-light text-dark border"><?= h($user['Departement_libelle'] ?? '') ?></span>
                <hr>
                <table class="table table-sm table-borderless text-start small mb-0">
                    <tr><th class="text-muted">Matricule</th><td><?= h($user['Matricule']) ?></td></tr>
                    <tr><th class="text-muted">Membre depuis</th><td><?= formatDate($user['date_creation']) ?></td></tr>
                    <tr><th class="text-muted">Dernière connexion</th><td><?= $user['derniere_connexion'] ? formatDate($user['derniere_connexion'], true) : '—' ?></td></tr>
                    <?php if (!empty($user['Profil'])): ?>
                        <tr><th class="text-muted">Profil / compétences</th><td class="text-start"><?= nl2br(h($user['Profil'])) ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endforeach; ?>

        <div class="card mb-4">
            <div class="card-header">Informations personnelles</div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_info">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Nom</label>
                            <input type="text" class="form-control" value="<?= h($user['Nom']) ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Prénom</label>
                            <input type="text" class="form-control" value="<?= h($user['Prenom']) ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Email personnel</label>
                            <input type="email" name="Mail" class="form-control" value="<?= h($user['Mail']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Email professionnel</label>
                            <input type="email" name="Mail_Pro" class="form-control" value="<?= h($user['Mail_Pro'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Contact</label>
                            <input type="text" name="Contact" class="form-control" value="<?= h($user['Contact'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Photo de profil</label>
                            <input type="file" name="photo" class="form-control" accept=".jpg,.jpeg,.png">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Mes profils / domaines de compétence</label>
                            <textarea name="Profil" class="form-control" rows="3"
                                      placeholder="Décrivez vos domaines de compétence, spécialités, certifications..."><?= h($user['Profil'] ?? '') ?></textarea>
                            <div class="form-text">Renseignez librement vos compétences ; cette information aide à l'attribution des activités.</div>
                        </div>
                    </div>
                    <p class="text-muted small mt-2 mb-0">Le nom, le prénom, le matricule, le rôle et le département sont gérés par l'administrateur.</p>
                    <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-check2"></i> Enregistrer</button>
                </form>
            </div>
        </div>

        <?php if ($user['role'] === 'directeur'): ?>
        <?php
        $secActuelle = null;
        if (!empty($user['ID_secretaire'])) {
            $stmtSec = $pdo->prepare('SELECT ID_agent_dpip, Nom, Prenom FROM agent_dpip WHERE ID_agent_dpip = ?');
            $stmtSec->execute([$user['ID_secretaire']]);
            $secActuelle = $stmtSec->fetch() ?: null;
        }
        $candidats = $pdo->query("SELECT ID_agent_dpip, Nom, Prenom, role, type_responsable FROM agent_dpip WHERE role IN ('agent','responsable') AND statut_compte = 'actif' ORDER BY Nom")->fetchAll();
        ?>
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-person-badge"></i> Délégation à ma secrétaire</div>
            <div class="card-body">
                <p class="text-muted small">Désignez un compte (agent ou responsable) qui pourra <strong>agir en votre nom</strong> pendant vos absences : consulter votre tableau de bord, déléguer et suivre les activités. Toutes ses actions restent enregistrées à son nom.</p>
                <form method="post" class="row g-2 align-items-end">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_secretaire">
                    <div class="col-md-8">
                        <label class="form-label small fw-semibold">Secrétaire déléguée</label>
                        <select name="ID_secretaire" class="form-select">
                            <option value="">— Aucune —</option>
                            <?php foreach ($candidats as $c): ?>
                                <option value="<?= $c['ID_agent_dpip'] ?>" <?= (int)($user['ID_secretaire'] ?? 0) === (int)$c['ID_agent_dpip'] ? 'selected' : '' ?>>
                                    <?= h($c['Prenom'] . ' ' . $c['Nom']) ?> (<?= h(roleLabelCompte($c)) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check2"></i> Enregistrer</button>
                    </div>
                </form>
                <?php if ($secActuelle): ?>
                    <div class="alert alert-info mt-3 mb-0 small"><i class="bi bi-info-circle"></i> <strong><?= h($secActuelle['Prenom'] . ' ' . $secActuelle['Nom']) ?></strong> peut agir en votre nom. Elle verra le bouton « Agir pour le Directeur » dans son menu.</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">Changer mon mot de passe</div>
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_password">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Mot de passe actuel</label>
                            <input type="password" name="ancien_mot_de_passe" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Nouveau mot de passe</label>
                            <input type="password" name="nouveau_mot_de_passe" class="form-control" minlength="6" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Confirmation</label>
                            <input type="password" name="confirmation_mot_de_passe" class="form-control" minlength="6" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-outline-primary mt-3"><i class="bi bi-key"></i> Modifier le mot de passe</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
