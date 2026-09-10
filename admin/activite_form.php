<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = requireRole(['admin']);

$id = (int)get('id');
$edition = $id > 0;
$pageTitle = $edition ? 'Modifier une activité / tâche' : 'Nouvelle activité / tâche';

$errors = [];
$data = [
    'Libelle' => '', 'Observation' => '', 'Types' => 'activite', 'Produit' => '', 'pere_ID' => '',
    'Date_debut' => date('Y-m-d'), 'Date_fin' => '', 'ID_agent_dpip' => '', 'ID_responsable' => '',
    'ID_departement' => '', 'Priorite' => 'moyenne', 'Statut' => 'a_faire',
];

if ($edition) {
    $stmt = $pdo->prepare('SELECT * FROM activites WHERE ID__activites = ?');
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        flash('danger', 'Activité introuvable.');
        redirect('/admin/activites.php');
    }
    $data = array_merge($data, [
        'Libelle' => $existing['Libelle'],
        'Observation' => $existing['Observation'] ?? '',
        'Types' => $existing['Types'],
        'Produit' => $existing['Produit'] ?? '',
        'pere_ID' => (string)($existing['pere_ID'] ?? ''),
        'Date_debut' => $existing['Date_debut'],
        'Date_fin' => $existing['Date_fin'],
        'ID_agent_dpip' => (string)$existing['ID_agent_dpip'],
        'ID_responsable' => (string)$existing['ID_responsable'],
        'ID_departement' => (string)($existing['ID_departement'] ?? ''),
        'Priorite' => $existing['Priorite'],
        'Statut' => $existing['Statut'],
    ]);
}

$responsables = $pdo->query("SELECT ID_agent_dpip, Nom, Prenom, role, type_responsable FROM agent_dpip WHERE " . sqlEstRespService() . " AND statut_compte = 'actif' ORDER BY Nom")->fetchAll();
$agents = $pdo->query(
    "SELECT ag.ID_agent_dpip, ag.Nom, ag.Prenom, ag.role, ag.est_agent, ag.ID_responsable, ag.ID_departement, ag.Profil AS profils
     FROM agent_dpip ag WHERE " . sqlEstAgent('ag.') . " AND ag.statut_compte = 'actif' ORDER BY ag.Nom"
)->fetchAll();
$departements = $pdo->query('SELECT * FROM departement ORDER BY Libelle')->fetchAll();
$peres = candidatsPereActivite($pdo, $edition ? $id : null);
$causesReafectation = causesReafectation($pdo);

$idsResponsables = array_column($responsables, null, 'ID_agent_dpip');
$idsAgents = array_column($agents, null, 'ID_agent_dpip');
$idsCauses = array_column($causesReafectation, null, 'id_cause');
$execActuelId = $edition ? (int)$existing['ID_agent_dpip'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    foreach (['Libelle', 'Observation', 'Types', 'Produit', 'pere_ID', 'Date_debut', 'Date_fin', 'ID_agent_dpip', 'ID_responsable', 'ID_departement', 'Priorite', 'Statut'] as $f) {
        $data[$f] = post($f, $data[$f]);
    }
    $data['id_cause'] = post('id_cause');
    $data['cause_commentaire'] = post('cause_commentaire');
    $data['Priorite'] = mb_substr(trim($data['Priorite']), 0, 50) ?: 'moyenne';

    $reaffectation = $edition && (int)$existing['ID_agent_dpip'] !== (int)$data['ID_agent_dpip'] && $data['ID_agent_dpip'] !== '';
    if ($reaffectation && ($data['id_cause'] === '' || !isset($idsCauses[$data['id_cause']]))) {
        $errors[] = 'Vous changez l\'agent en charge : sélectionnez une cause de réaffectation.';
    }

    if ($data['Libelle'] === '') $errors[] = 'Le libellé est obligatoire.';
    if (!in_array($data['Types'], typesActivite(), true)) $errors[] = 'Type invalide.';
    [$pereId, $errPere] = validerPereActivite($pdo, $data['Types'], $data['pere_ID'], $edition ? $id : null);
    if ($errPere) $errors[] = $errPere;
    if ($data['ID_responsable'] === '' || !isset($idsResponsables[$data['ID_responsable']])) $errors[] = 'Veuillez sélectionner un responsable valide.';
    if ($data['ID_agent_dpip'] === '' || !isset($idsAgents[$data['ID_agent_dpip']])) $errors[] = 'Veuillez sélectionner un agent valide.';
    if ($data['Date_debut'] === '' || $data['Date_fin'] === '') $errors[] = 'Les dates de début et de fin sont obligatoires.';
    elseif (strtotime($data['Date_fin']) < strtotime($data['Date_debut'])) $errors[] = 'La date de fin doit être postérieure à la date de début.';
    if ($edition && !in_array($data['Statut'], ['a_faire', 'en_cours', 'en_attente', 'termine', 'en_retard', 'annule'], true)) $errors[] = 'Statut invalide.';

    if (!$errors) {
        // Département : celui choisi, sinon celui de l'agent
        $deptId = $data['ID_departement'] !== '' ? (int)$data['ID_departement'] : (int)($idsAgents[$data['ID_agent_dpip']]['ID_departement'] ?? 0);
        try {
            if ($edition) {
                $pdo->prepare(
                    'UPDATE activites SET Libelle=?, Observation=?, Types=?, Produit=?, pere_ID=?, Date_debut=?, Date_fin=?,
                            ID_agent_dpip=?, ID_responsable=?, ID_departement=?, Priorite=?, Statut=? WHERE ID__activites=?'
                )->execute([
                    $data['Libelle'], $data['Observation'] ?: null, $data['Types'], $data['Produit'] ?: null, $pereId,
                    $data['Date_debut'], $data['Date_fin'], $data['ID_agent_dpip'], $data['ID_responsable'],
                    $deptId ?: null, $data['Priorite'], $data['Statut'], $id,
                ]);
                logJournal($pdo, $id, (int)$user['ID_agent_dpip'], 'modification', typeActiviteLabel($data['Types']) . ' modifiée par l\'administrateur');

                $agentAChange = (int)$existing['ID_agent_dpip'] !== (int)$data['ID_agent_dpip'];
                $agentStmt = $pdo->prepare('SELECT * FROM agent_dpip WHERE ID_agent_dpip = ?');
                $agentStmt->execute([$data['ID_agent_dpip']]);
                $agentInfo = $agentStmt->fetch();
                $mailData = ['Libelle' => $data['Libelle'], 'Date_fin' => $data['Date_fin'], 'Types' => $data['Types']];

                if ($agentAChange) {
                    $idCause = isset($idsCauses[$data['id_cause']]) ? (int)$data['id_cause'] : null;
                    $causeTxt = $idCause ? ($idsCauses[$data['id_cause']]['libelle'] ?? '') : '';
                    enregistrerReafectation($pdo, $id, (int)$existing['ID_agent_dpip'], (int)$data['ID_agent_dpip'], $idCause, (int)$user['ID_agent_dpip'], $data['cause_commentaire']);
                    logJournal($pdo, $id, (int)$user['ID_agent_dpip'], 'reafectation', 'Réaffectée de ' . nomAgent($pdo, (int)$existing['ID_agent_dpip']) . ' à ' . $agentInfo['Prenom'] . ' ' . $agentInfo['Nom'] . ($causeTxt ? ' — cause : ' . $causeTxt : ''));
                    createNotification($pdo, (int)$data['ID_agent_dpip'], 'Nouvelle attribution', 'Vous avez une nouvelle ' . mb_strtolower(typeActiviteLabel($data['Types'])) . ' : "' . $data['Libelle'] . '".', '/tache_detail.php?id=' . $id);
                    if ((int)$existing['ID_agent_dpip'] > 0) {
                        createNotification($pdo, (int)$existing['ID_agent_dpip'], 'Activité réaffectée', 'L\'activité "' . $data['Libelle'] . '" ne vous est plus attribuée' . ($causeTxt ? ' (' . $causeTxt . ')' : '') . '.', '/tache_detail.php?id=' . $id);
                    }
                    mailNouvelleTache($agentInfo, $mailData);
                } else {
                    createNotification($pdo, (int)$data['ID_agent_dpip'], 'Activité mise à jour', 'L\'activité "' . $data['Libelle'] . '" a été modifiée.', '/tache_detail.php?id=' . $id);
                    mailActiviteModifiee($agentInfo, $mailData);
                }
                if (trim((string)$data['Observation']) !== '' && trim((string)$data['Observation']) !== trim((string)($existing['Observation'] ?? ''))) {
                    notifierObservationActivite($pdo, $id, (int)$data['ID_agent_dpip'], (int)$user['ID_agent_dpip'], $user['Prenom'] . ' ' . $user['Nom'], $data['Libelle']);
                }
                flash('success', 'Activité mise à jour.');
                redirect('/tache_detail.php?id=' . $id);
            }

            $pdo->prepare(
                'INSERT INTO activites (Libelle, Observation, Types, Produit, pere_ID, Date_debut, Date_fin, ID_agent_dpip, ID_responsable, ID_departement, Priorite)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $data['Libelle'], $data['Observation'] ?: null, $data['Types'], $data['Produit'] ?: null, $pereId,
                $data['Date_debut'], $data['Date_fin'], $data['ID_agent_dpip'], $data['ID_responsable'], $deptId ?: null, $data['Priorite'],
            ]);
            $newId = (int)$pdo->lastInsertId();

            logJournal($pdo, $newId, (int)$user['ID_agent_dpip'], 'creation', typeActiviteLabel($data['Types']) . ' créée par l\'administrateur');
            createNotification($pdo, (int)$data['ID_agent_dpip'], 'Nouvelle attribution', 'Vous avez une nouvelle ' . mb_strtolower(typeActiviteLabel($data['Types'])) . ' : "' . $data['Libelle'] . '".', '/tache_detail.php?id=' . $newId);
            createNotification($pdo, (int)$data['ID_responsable'], 'Nouvelle activité dans votre périmètre', 'Une ' . mb_strtolower(typeActiviteLabel($data['Types'])) . ' "' . $data['Libelle'] . '" a été créée par l\'administrateur.', '/tache_detail.php?id=' . $newId);
            if (trim((string)$data['Observation']) !== '') {
                notifierObservationActivite($pdo, $newId, (int)$data['ID_agent_dpip'], (int)$user['ID_agent_dpip'], $user['Prenom'] . ' ' . $user['Nom'], $data['Libelle']);
            }

            $agentStmt = $pdo->prepare('SELECT * FROM agent_dpip WHERE ID_agent_dpip = ?');
            $agentStmt->execute([$data['ID_agent_dpip']]);
            $agentInfo = $agentStmt->fetch();
            mailNouvelleTache($agentInfo, ['Libelle' => $data['Libelle'], 'Date_fin' => $data['Date_fin'], 'Types' => $data['Types']]);

            flash('success', typeActiviteLabel($data['Types']) . ' créée avec succès.');
            redirect('/tache_detail.php?id=' . $newId);
        } catch (Throwable $e) {
            $errors[] = 'Erreur : ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endforeach; ?>
        <?php if (empty($responsables) || empty($agents)): ?>
            <div class="alert alert-warning">Créez au moins un compte <strong>responsable</strong> et un compte <strong>agent</strong> actifs avant de saisir une activité.</div>
        <?php endif; ?>
        <div class="card">
            <div class="card-header"><?= $edition ? 'Modifier l\'activité / la tâche' : 'Nouvelle activité, sous-activité ou tâche' ?></div>
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Type *</label>
                            <select name="Types" class="form-select" required>
                                <?php foreach (typesActivite() as $t): ?>
                                    <option value="<?= $t ?>" <?= $data['Types'] === $t ? 'selected' : '' ?>><?= typeActiviteLabel($t) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Activité &gt; Sous-activité &gt; Tâche</div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small fw-semibold">Libellé *</label>
                            <input type="text" name="Libelle" class="form-control" value="<?= h($data['Libelle']) ?>" required>
                        </div>
                        <?php include __DIR__ . '/../includes/champ_pere_activite.php'; ?>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Note</label>
                            <textarea name="Observation" class="form-control" rows="3" placeholder="Faire une note sur cette activité, sous-activité ou tâche..."><?= h($data['Observation']) ?></textarea>
                            <div class="form-text">Faites une note sur les activités, sous-activités ou tâches.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Agent en charge (exécutant) *</label>
                            <select name="ID_agent_dpip" id="agentSelect" data-exec-select class="form-select" required>
                                <option value="">— Choisir —</option>
                                <?php foreach ($agents as $a): ?>
                                    <option value="<?= $a['ID_agent_dpip'] ?>" data-resp="<?= (int)$a['ID_responsable'] ?>" <?= $data['ID_agent_dpip'] == $a['ID_agent_dpip'] ? 'selected' : '' ?>>
                                        <?= h($a['Prenom'] . ' ' . $a['Nom']) ?><?= $a['role'] !== 'agent' ? ' (' . h(roleLabel($a['role'])) . ')' : '' ?><?= $a['profils'] ? ' — ' . h($a['profils']) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php include __DIR__ . '/../includes/champ_cause_reafectation.php'; ?>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Responsable Service / Sous-directeur *</label>
                            <select name="ID_responsable" id="respSelect" class="form-select" required>
                                <option value="">— Choisir —</option>
                                <?php foreach ($responsables as $r): ?>
                                    <?php $hint = ($r['role'] === 'responsable' && ($r['type_responsable'] ?? 'service') !== 'sous_directeur') ? '' : roleLabelCompte($r); ?>
                                    <option value="<?= $r['ID_agent_dpip'] ?>" <?= $data['ID_responsable'] == $r['ID_agent_dpip'] ? 'selected' : '' ?>><?= h($r['Prenom'] . ' ' . $r['Nom']) ?><?= $hint ? ' (' . h($hint) . ')' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Pré-rempli avec le responsable hiérarchique de l'agent.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Département</label>
                            <select name="ID_departement" class="form-select">
                                <option value="">— Celui de l'agent —</option>
                                <?php foreach ($departements as $d): ?>
                                    <option value="<?= $d['id_departement'] ?>" <?= (string)$data['ID_departement'] === (string)$d['id_departement'] ? 'selected' : '' ?>><?= h(($d['code_departement'] ?? codeDepartement($d['id_departement'])) . ' · ' . $d['Nom_court']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Produit attendu</label>
                            <input type="text" name="Produit" class="form-control" value="<?= h($data['Produit']) ?>">
                        </div>
                        <div class="col-md-4">
                            <?php $prioriteValeur = $data['Priorite']; include __DIR__ . '/../includes/champ_priorite.php'; ?>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Date de début *</label>
                            <input type="date" name="Date_debut" class="form-control" value="<?= h($data['Date_debut']) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Date de fin (échéance) *</label>
                            <input type="date" name="Date_fin" class="form-control" value="<?= h($data['Date_fin']) ?>" required>
                        </div>
                        <?php if ($edition): ?>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Statut</label>
                            <select name="Statut" class="form-select">
                                <?php foreach (['a_faire','en_cours','en_attente','termine','en_retard','annule'] as $s): ?>
                                    <option value="<?= $s ?>" <?= $data['Statut'] === $s ? 'selected' : '' ?>><?= statutLabel($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary" <?= empty($responsables) || empty($agents) ? 'disabled' : '' ?>>
                            <i class="bi bi-check2"></i> <?= $edition ? 'Enregistrer' : 'Créer' ?>
                        </button>
                        <a href="<?= BASE_URL ?>/admin/activites.php" class="btn btn-light border">Annuler</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var agentSel = document.getElementById('agentSelect');
    var respSel = document.getElementById('respSelect');
    if (!agentSel || !respSel) return;
    agentSel.addEventListener('change', function () {
        var opt = agentSel.options[agentSel.selectedIndex];
        var resp = opt ? opt.getAttribute('data-resp') : '';
        if (resp && resp !== '0') respSel.value = resp;
    });
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
