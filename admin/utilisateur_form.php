<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = requireRole(['admin']);

$id = (int)get('id');
$edition = $id > 0;
$pageTitle = $edition ? 'Modifier un utilisateur' : 'Nouvel utilisateur';
$errors = [];
$tempPassword = null;

$data = [
    'Nom' => '', 'Prenom' => '', 'Matricule' => '', 'Mail' => '', 'Mail_Pro' => '',
    'Contact' => '', 'Profil' => '', 'ID_departement' => '', 'role' => 'agent', 'type_responsable' => null, 'ID_responsable' => '',
    'est_responsable_service' => 0, 'est_agent' => 0, 'est_admin' => 0, 'statut_compte' => 'actif',
];

if ($edition) {
    $stmt = $pdo->prepare('SELECT * FROM agent_dpip WHERE ID_agent_dpip = ?');
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        flash('danger', 'Utilisateur introuvable.');
        redirect('/admin/utilisateurs.php');
    }
    $data = array_merge($data, $existing);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $data['Nom'] = post('Nom');
    $data['Prenom'] = post('Prenom');
    $data['Matricule'] = post('Matricule');
    $data['Mail'] = post('Mail');
    $data['Mail_Pro'] = post('Mail_Pro');
    $data['Contact'] = post('Contact');
    $data['Profil'] = post('Profil');
    $data['ID_departement'] = post('ID_departement');
    // Le rôle « Sous-directeur » est un « responsable » avec type_responsable = sous_directeur.
    $roleBrut = post('role');
    if ($roleBrut === 'sous_directeur') {
        $data['role'] = 'responsable';
        $data['type_responsable'] = 'sous_directeur';
    } elseif ($roleBrut === 'responsable') {
        $data['role'] = 'responsable';
        $data['type_responsable'] = 'service';
    } else {
        $data['role'] = $roleBrut;
        $data['type_responsable'] = null;
    }
    // Un Responsable Service et un Sous-directeur ont pour responsable hiérarchique le Directeur
    // (champ ID_directeur) ; les agents se rattachent à un Responsable Service / Sous-directeur (champ ID_responsable).
    $rattacheDirecteur = in_array($roleBrut, ['responsable', 'sous_directeur'], true);
    $data['ID_responsable'] = $rattacheDirecteur ? post('ID_directeur') : post('ID_responsable');
    // Fonctions secondaires : un « responsable » / « agent » l'a par nature, sinon case cochée.
    $data['est_responsable_service'] = ($data['role'] === 'responsable' || post('est_responsable_service') === '1') ? 1 : 0;
    $data['est_agent'] = ($data['role'] === 'agent' || post('est_agent') === '1') ? 1 : 0;
    // Fonction supplémentaire « Administrateur » : réservée au Responsable Service (le Sous-directeur n'y a pas droit).
    $data['est_admin'] = ($roleBrut === 'responsable' && post('est_admin') === '1') ? 1 : 0;
    $data['statut_compte'] = post('statut_compte', 'actif');

    if ($data['Nom'] === '' || $data['Prenom'] === '' || $data['Matricule'] === '') {
        $errors[] = 'Le nom, le prénom et le matricule sont obligatoires.';
    }
    if ($data['Mail'] === '' || !filter_var($data['Mail'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Un email personnel valide est requis.';
    }
    if ($data['ID_departement'] === '') {
        $errors[] = 'Le département est obligatoire.';
    }
    if (!in_array($data['role'], ['admin', 'directeur', 'responsable', 'agent'], true)) {
        $errors[] = 'Rôle invalide.';
    }
    $besoinResponsable = ($data['role'] === 'agent' || $data['est_agent'] === 1 || $rattacheDirecteur);
    if ($besoinResponsable && $data['ID_responsable'] === '') {
        $errors[] = $rattacheDirecteur
            ? 'Un Responsable Service ou un Sous-directeur doit être rattaché à un Directeur (son responsable hiérarchique).'
            : 'Une personne qui reçoit des activités (agent) doit être rattachée à un Responsable Service / Sous-directeur.';
    }
    if ($rattacheDirecteur && $data['ID_responsable'] !== '') {
        $chk = $pdo->prepare("SELECT 1 FROM agent_dpip WHERE ID_agent_dpip = ? AND role = 'directeur' AND statut_compte = 'actif'");
        $chk->execute([(int)$data['ID_responsable']]);
        if (!$chk->fetchColumn()) {
            $errors[] = 'Le responsable d\'un Responsable Service ou d\'un Sous-directeur doit être un Directeur actif.';
        }
    }
    $idResponsable = $besoinResponsable && $data['ID_responsable'] !== '' ? (int)$data['ID_responsable'] : null;
    if ($idResponsable === $id && $edition) {
        $errors[] = 'Un utilisateur ne peut pas être son propre responsable.';
    }

    if (!$errors) {
        try {
            if ($edition) {
                $pdo->prepare(
                    'UPDATE agent_dpip SET Nom=?, Prenom=?, Matricule=?, Mail=?, Mail_Pro=?, Contact=?, Profil=?, ID_departement=?, role=?, type_responsable=?, est_responsable_service=?, est_agent=?, est_admin=?, ID_responsable=?, statut_compte=? WHERE ID_agent_dpip=?'
                )->execute([
                    $data['Nom'], $data['Prenom'], $data['Matricule'], $data['Mail'], $data['Mail_Pro'] ?: null,
                    $data['Contact'] ?: null, $data['Profil'] ?: null, $data['ID_departement'], $data['role'], $data['type_responsable'], $data['est_responsable_service'], $data['est_agent'], $data['est_admin'], $idResponsable, $data['statut_compte'], $id,
                ]);
                if ($id === (int)$user['ID_agent_dpip']) {
                    refreshCurrentUser($pdo);
                }
                logModif($pdo, 'agent_dpip', $id, 'modification', 'Compte « ' . $data['Prenom'] . ' ' . $data['Nom'] . ' » (' . roleLabelCompte($data) . ') modifié');
                flash('success', 'Utilisateur mis à jour avec succès.');
                redirect('/admin/utilisateurs.php');
            } else {
                $pdo->prepare(
                    'INSERT INTO agent_dpip (Nom, Prenom, Matricule, Mail, Mail_Pro, Contact, Profil, ID_departement, mot_de_passe, role, type_responsable, est_responsable_service, est_agent, est_admin, ID_responsable, statut_compte)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                )->execute([
                    $data['Nom'], $data['Prenom'], $data['Matricule'], $data['Mail'], $data['Mail_Pro'] ?: null,
                    $data['Contact'] ?: null, $data['Profil'] ?: null, $data['ID_departement'], '',
                    $data['role'], $data['type_responsable'], $data['est_responsable_service'], $data['est_agent'], $data['est_admin'], $idResponsable, 'non_active',
                ]);
                logModif($pdo, 'agent_dpip', (int)$pdo->lastInsertId(), 'creation', 'Compte « ' . $data['Prenom'] . ' ' . $data['Nom'] . ' » créé (' . roleLabelCompte($data) . ', matricule ' . $data['Matricule'] . ')');
                flash('success', "Compte créé (matricule « " . $data['Matricule'] . " »). Il est « À activer » : cliquez sur le bouton ✔ dans la liste pour l'activer, ensuite l'utilisateur pourra définir son mot de passe via « Première connexion ».");
                redirect('/admin/utilisateurs.php');
            }
        } catch (Throwable $e) {
            $errors[] = str_contains($e->getMessage(), 'Duplicate')
                ? 'Ce matricule ou cet email est déjà utilisé par un autre compte.'
                : 'Erreur : ' . $e->getMessage();
        }
    }
}

$departements = $pdo->query('SELECT * FROM departement ORDER BY Libelle')->fetchAll();
$responsables = $pdo->query("SELECT * FROM agent_dpip WHERE " . sqlEstRespService() . " AND statut_compte = 'actif' ORDER BY Nom")->fetchAll();
$directeurs = $pdo->query("SELECT * FROM agent_dpip WHERE role = 'directeur' AND statut_compte = 'actif' ORDER BY Nom")->fetchAll();
// Directeur proposé d'office pour un Responsable Service / Sous-directeur : celui déjà enregistré,
// sinon l'unique directeur actif.
$directeurChoisi = (string)($data['ID_responsable'] ?? '');
if (!in_array($directeurChoisi, array_map('strval', array_column($directeurs, 'ID_agent_dpip')), true) && count($directeurs) === 1) {
    $directeurChoisi = (string)$directeurs[0]['ID_agent_dpip'];
}

include __DIR__ . '/../includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endforeach; ?>
        <div class="card">
            <div class="card-header"><?= $edition ? 'Modifier l\'utilisateur' : 'Créer un nouvel utilisateur' ?></div>
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Nom *</label>
                            <input type="text" name="Nom" class="form-control" value="<?= h($data['Nom']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Prénom *</label>
                            <input type="text" name="Prenom" class="form-control" value="<?= h($data['Prenom']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Matricule *</label>
                            <input type="text" name="Matricule" class="form-control" value="<?= h($data['Matricule']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Contact</label>
                            <input type="text" name="Contact" class="form-control" value="<?= h($data['Contact'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Email personnel *</label>
                            <input type="email" name="Mail" class="form-control" value="<?= h($data['Mail']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Email professionnel</label>
                            <input type="email" name="Mail_Pro" class="form-control" value="<?= h($data['Mail_Pro'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Profils / domaines de compétence</label>
                            <textarea name="Profil" class="form-control" rows="2"
                                      placeholder="Ex. : Statisticien, spécialiste du suivi-évaluation des projets d'investissement public..."><?= h($data['Profil'] ?? '') ?></textarea>
                            <div class="form-text">Domaine(s) de compétence de la personne. L'intéressé(e) peut aussi le compléter lui-même depuis « Mon profil ».</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Département *</label>
                            <select name="ID_departement" class="form-select" required>
                                <option value="">— Choisir —</option>
                                <?php foreach ($departements as $d): ?>
                                    <option value="<?= $d['id_departement'] ?>" <?= (string)$data['ID_departement'] === (string)$d['id_departement'] ? 'selected' : '' ?>>
                                        <?= h(($d['code_departement'] ?? codeDepartement($d['id_departement'])) . ' · ' . $d['Nom_court'] . ' — ' . $d['Libelle']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <?php
                            $roleSelectValue = $data['role'] === 'responsable'
                                ? (($data['type_responsable'] ?? 'service') === 'sous_directeur' ? 'sous_directeur' : 'responsable')
                                : $data['role'];
                            ?>
                            <label class="form-label small fw-semibold">Rôle principal *</label>
                            <select name="role" id="roleSelect" class="form-select" required onchange="toggleResponsable()">
                                <option value="agent" <?= $roleSelectValue === 'agent' ? 'selected' : '' ?>>Agent</option>
                                <option value="responsable" <?= $roleSelectValue === 'responsable' ? 'selected' : '' ?>>Responsable Service</option>
                                <option value="sous_directeur" <?= $roleSelectValue === 'sous_directeur' ? 'selected' : '' ?>>Sous-directeur</option>
                                <option value="directeur" <?= $roleSelectValue === 'directeur' ? 'selected' : '' ?>>Directeur</option>
                                <option value="admin" <?= $roleSelectValue === 'admin' ? 'selected' : '' ?>>Administrateur</option>
                            </select>
                        </div>
                        <div class="col-md-6" id="responsableWrapper">
                            <label class="form-label small fw-semibold">Responsable Service / Sous-directeur *</label>
                            <select name="ID_responsable" class="form-select">
                                <option value="">— Choisir —</option>
                                <?php foreach ($responsables as $r): ?>
                                    <?php $hint = ($r['role'] === 'responsable' && ($r['type_responsable'] ?? 'service') !== 'sous_directeur') ? '' : roleLabelCompte($r); ?>
                                    <option value="<?= $r['ID_agent_dpip'] ?>" <?= (string)$data['ID_responsable'] === (string)$r['ID_agent_dpip'] ? 'selected' : '' ?>>
                                        <?= h($r['Prenom'] . ' ' . $r['Nom']) ?><?= $hint ? ' (' . h($hint) . ')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($responsables)): ?>
                                <div class="form-text text-warning">Aucun responsable service actif n'existe encore.</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6" id="directeurWrapper" style="display:none;">
                            <label class="form-label small fw-semibold">Directeur (responsable hiérarchique) *</label>
                            <select name="ID_directeur" id="directeurSelect" class="form-select">
                                <option value="">— Choisir —</option>
                                <?php foreach ($directeurs as $dir): ?>
                                    <option value="<?= $dir['ID_agent_dpip'] ?>" <?= $directeurChoisi === (string)$dir['ID_agent_dpip'] ? 'selected' : '' ?>>
                                        <?= h($dir['Prenom'] . ' ' . $dir['Nom']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text<?= empty($directeurs) ? ' text-warning' : '' ?>">
                                <?= empty($directeurs) ? 'Aucun Directeur actif n\'existe encore : créez d\'abord le compte du Directeur.' : 'Un Responsable Service ou un Sous-directeur rend compte au Directeur.' ?>
                            </div>
                        </div>
                        <div class="col-12" id="respServiceWrapper">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="est_responsable_service" id="respServiceCheck" value="1" <?= !empty($data['est_responsable_service']) ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="respServiceCheck">
                                    <strong>Fonction supplémentaire : Responsable Service / Sous-directeur</strong>
                                    <span class="text-muted d-block">Coché, cette personne (quel que soit son rôle principal, ex. Administrateur) pourra être désignée comme responsable d'un agent et attributaire d'activités. Son nom apparaîtra dans la colonne « Responsable » des utilisateurs qui lui sont rattachés.</span>
                                </label>
                            </div>
                        </div>
                        <div class="col-12" id="adminWrapper" style="display:none;">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="est_admin" id="adminCheck" value="1" <?= !empty($data['est_admin']) ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="adminCheck">
                                    <strong>Fonction supplémentaire : Administrateur</strong>
                                    <span class="text-muted d-block">Coché, ce Responsable Service garde son espace de responsable (attribuer des activités à ses agents, comptes rendus…) et obtient en plus l'espace Administration : utilisateurs, départements, historique, rapports, toutes les activités. Son responsable reste le Directeur.</span>
                                </label>
                            </div>
                        </div>
                        <div class="col-12" id="agentWrapper">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="est_agent" id="agentCheck" value="1" onchange="toggleResponsable()" <?= !empty($data['est_agent']) ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="agentCheck">
                                    <strong>Fonction supplémentaire : Agent (peut recevoir des activités)</strong>
                                    <span class="text-muted d-block">Coché, cette personne (ex. Administrateur) pourra recevoir des activités de son Responsable Service / Sous-directeur et en rendre compte, en plus de son rôle principal. Un Responsable Service / Sous-directeur doit alors lui être rattaché.</span>
                                </label>
                            </div>
                        </div>
                        <?php if ($edition): ?>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Statut du compte</label>
                            <select name="statut_compte" class="form-select">
                                <?php foreach (['actif', 'inactif', 'en_attente', 'non_active'] as $s): ?>
                                    <option value="<?= $s ?>" <?= $data['statut_compte'] === $s ? 'selected' : '' ?>><?= h(statutCompteLabel($s)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!$edition): ?>
                        <div class="alert alert-info mt-3 mb-0 small">
                            <i class="bi bi-info-circle"></i> À la création, le compte est <strong>« À activer »</strong>. Vous devez l'<strong>activer</strong> depuis la liste des utilisateurs (bouton ✔) ; l'utilisateur pourra ensuite définir son mot de passe via <strong>« Première connexion »</strong> (matricule + nouveau mot de passe), puis se connecter.
                        </div>
                    <?php endif; ?>
                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check2"></i> Enregistrer</button>
                        <a href="<?= BASE_URL ?>/admin/utilisateurs.php" class="btn btn-light border">Annuler</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
function toggleResponsable() {
    var role = document.getElementById('roleSelect').value;
    var estResp = (role === 'responsable' || role === 'sous_directeur');
    var agentChk = document.getElementById('agentCheck');
    var respChk = document.getElementById('respServiceCheck');

    // Fonction « Agent » implicite pour le rôle principal Agent : case cochée + verrouillée.
    var agentWrap = document.getElementById('agentWrapper');
    var rattacheDir = (role === 'responsable' || role === 'sous_directeur');
    // Case cochée d'office : on la décoche quand on quitte ce rôle (sinon elle serait envoyée à tort).
    if (role === 'agent') {
        if (!agentChk.checked) { agentChk.dataset.auto = '1'; }
        agentChk.checked = true; agentChk.disabled = true; agentWrap.style.display = 'none';
    } else {
        if (agentChk.dataset.auto === '1') { agentChk.checked = false; agentChk.dataset.auto = '0'; }
        agentChk.disabled = false; agentWrap.style.display = rattacheDir ? 'none' : '';
    }

    // Le rattachement à un Responsable Service est requis pour tout « agent » (rôle ou fonction).
    // Un Responsable Service ou un Sous-directeur, lui, est rattaché au Directeur.
    var recoitActivites = (role === 'agent') || (agentChk.checked && !rattacheDir);
    document.getElementById('responsableWrapper').style.display = recoitActivites ? '' : 'none';
    document.getElementById('directeurWrapper').style.display = rattacheDir ? '' : 'none';
    // « Administrateur » en fonction supplémentaire : proposé uniquement pour un Responsable Service.
    var adminWrap = document.getElementById('adminWrapper');
    adminWrap.style.display = (role === 'responsable') ? '' : 'none';
    if (role !== 'responsable') { document.getElementById('adminCheck').checked = false; }
    document.getElementById('directeurSelect').required = rattacheDir;

    // Fonction « Responsable Service » implicite pour un Responsable Service ou un Sous-directeur.
    var respWrap = document.getElementById('respServiceWrapper');
    if (estResp) {
        if (!respChk.checked) { respChk.dataset.auto = '1'; }
        respChk.checked = true; respChk.disabled = true; respWrap.style.display = 'none';
    } else {
        if (respChk.dataset.auto === '1') { respChk.checked = false; respChk.dataset.auto = '0'; }
        respChk.disabled = false; respWrap.style.display = '';
    }
}
document.addEventListener('DOMContentLoaded', toggleResponsable);
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
