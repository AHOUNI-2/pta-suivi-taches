<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$user = requireRole(['responsable']);
$respId = (int)$user['ID_agent_dpip'];

$id = (int)get('id');
$edition = $id > 0;
$pageTitle = $edition ? 'Modifier une activité / tâche' : 'Nouvelle activité / tâche';

$agents = $pdo->prepare(
    "SELECT ag.*, ag.Profil AS profils
     FROM agent_dpip ag WHERE ag.ID_responsable = ? AND ag.statut_compte = 'actif' ORDER BY ag.Nom"
);
$agents->execute([$respId]);
$agents = $agents->fetchAll();
$causesReafectation = causesReafectation($pdo);
$idsCauses = array_column($causesReafectation, null, 'id_cause');

$errors = [];
$data = [
    'Libelle' => '', 'Observation' => '', 'Types' => 'activite', 'Produit' => '', 'pere_ID' => '',
    'Date_debut' => date('Y-m-d'), 'Date_fin' => '', 'ID_agent_dpip' => '',
    'Priorite' => 'moyenne', 'Statut' => 'a_faire',
];
$peres = candidatsPereActivite($pdo, $edition ? $id : null);

if ($edition) {
    $stmt = $pdo->prepare('SELECT * FROM activites WHERE ID__activites = ?');
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing || !peutGererActivite($pdo, $user, $existing)) {
        flash('danger', "Activité introuvable, ou ni attribuée par vous ni confiée à l'un de vos agents.");
        redirect('/responsable/activites.php');
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
        'Priorite' => $existing['Priorite'],
        'Statut' => $existing['Statut'],
    ]);
}
$execActuelId = $edition ? (int)$existing['ID_agent_dpip'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    foreach (['Libelle', 'Observation', 'Types', 'Produit', 'pere_ID', 'Date_debut', 'Date_fin', 'ID_agent_dpip', 'Priorite', 'Statut'] as $f) {
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
    if ($data['ID_agent_dpip'] === '') $errors[] = 'Veuillez sélectionner un agent.';
    if ($data['Date_debut'] === '' || $data['Date_fin'] === '') $errors[] = 'Les dates de début et de fin sont obligatoires.';
    elseif (strtotime($data['Date_fin']) < strtotime($data['Date_debut'])) $errors[] = 'La date de fin doit être postérieure à la date de début.';

    $agentValide = false;
    foreach ($agents as $a) { if ((string)$a['ID_agent_dpip'] === $data['ID_agent_dpip']) { $agentValide = true; break; } }
    if ($data['ID_agent_dpip'] !== '' && !$agentValide) $errors[] = 'Agent invalide.';

    if ($edition && !in_array($data['Statut'], ['a_faire', 'en_cours', 'en_attente', 'termine', 'en_retard', 'annule'], true)) {
        $errors[] = 'Statut invalide.';
    }

    if (!$errors) {
        try {
            $agentDeptStmt = $pdo->prepare('SELECT ID_departement FROM agent_dpip WHERE ID_agent_dpip = ?');
            $agentDeptStmt->execute([$data['ID_agent_dpip']]);
            $deptId = $agentDeptStmt->fetchColumn();

            if ($edition) {
                $pdo->prepare(
                    'UPDATE activites SET Libelle=?, Observation=?, Types=?, Produit=?, pere_ID=?, Date_debut=?, Date_fin=?,
                            ID_agent_dpip=?, ID_departement=?, Priorite=?, Statut=? WHERE ID__activites=?'
                )->execute([
                    $data['Libelle'], $data['Observation'] ?: null, $data['Types'], $data['Produit'] ?: null, $pereId,
                    $data['Date_debut'], $data['Date_fin'], $data['ID_agent_dpip'], $deptId ?: null,
                    $data['Priorite'], $data['Statut'], $id,
                ]);
                majProgression($pdo, $id);
                if ((int)($existing['pere_ID'] ?? 0) !== (int)($pereId ?? 0)) {
                    majProgression($pdo, (int)($existing['pere_ID'] ?? 0));
                }
                logJournal($pdo, $id, $respId, 'modification', typeActiviteLabel($data['Types']) . ' modifiée par le responsable');

                $agentAChange = (int)$existing['ID_agent_dpip'] !== (int)$data['ID_agent_dpip'];
                $agentStmt = $pdo->prepare('SELECT * FROM agent_dpip WHERE ID_agent_dpip = ?');
                $agentStmt->execute([$data['ID_agent_dpip']]);
                $agentInfo = $agentStmt->fetch();

                if ($agentAChange) {
                    $idCause = isset($idsCauses[$data['id_cause']]) ? (int)$data['id_cause'] : null;
                    $causeTxt = $idCause ? ($idsCauses[$data['id_cause']]['libelle'] ?? '') : '';
                    enregistrerReafectation($pdo, $id, (int)$existing['ID_agent_dpip'], (int)$data['ID_agent_dpip'], $idCause, $respId, $data['cause_commentaire']);
                    logJournal($pdo, $id, $respId, 'reafectation', 'Réaffectée de ' . nomAgent($pdo, (int)$existing['ID_agent_dpip']) . ' à ' . $agentInfo['Prenom'] . ' ' . $agentInfo['Nom'] . ($causeTxt ? ' — cause : ' . $causeTxt : ''));
                    createNotification($pdo, (int)$data['ID_agent_dpip'], 'Nouvelle attribution', 'Vous avez une nouvelle ' . mb_strtolower(typeActiviteLabel($data['Types'])) . ' : "' . $data['Libelle'] . '".', '/tache_detail.php?id=' . $id);
                    if ((int)$existing['ID_agent_dpip'] > 0) {
                        createNotification($pdo, (int)$existing['ID_agent_dpip'], 'Activité réaffectée', 'L\'activité "' . $data['Libelle'] . '" ne vous est plus attribuée' . ($causeTxt ? ' (' . $causeTxt . ')' : '') . '.', '/tache_detail.php?id=' . $id);
                    }
                    mailNouvelleTache($agentInfo, ['Libelle' => $data['Libelle'], 'Date_fin' => $data['Date_fin']]);
                } else {
                    createNotification($pdo, (int)$data['ID_agent_dpip'], 'Activité mise à jour', 'L\'activité "' . $data['Libelle'] . '" a été modifiée par votre responsable.', '/tache_detail.php?id=' . $id);
                    mailActiviteModifiee($agentInfo, ['Libelle' => $data['Libelle'], 'Date_fin' => $data['Date_fin'], 'Types' => $data['Types']]);
                }
                if (trim((string)$data['Observation']) !== '' && trim((string)$data['Observation']) !== trim((string)($existing['Observation'] ?? ''))) {
                    notifierObservationActivite($pdo, $id, (int)$data['ID_agent_dpip'], $respId, $user['Prenom'] . ' ' . $user['Nom'], $data['Libelle']);
                }
                flash('success', 'Activité mise à jour.');
                redirect('/tache_detail.php?id=' . $id);
            }

            $pdo->prepare(
                'INSERT INTO activites (Libelle, Observation, Types, Produit, pere_ID, Date_debut, Date_fin, ID_agent_dpip, ID_responsable, ID_departement, Priorite)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $data['Libelle'], $data['Observation'] ?: null, $data['Types'], $data['Produit'] ?: null, $pereId,
                $data['Date_debut'], $data['Date_fin'], $data['ID_agent_dpip'], $respId, $deptId ?: null, $data['Priorite'],
            ]);
            $newId = (int)$pdo->lastInsertId();

            majProgression($pdo, $newId);
            logJournal($pdo, $newId, $respId, 'creation', typeActiviteLabel($data['Types']) . ' créée et attribuée');
            createNotification($pdo, (int)$data['ID_agent_dpip'], 'Nouvelle attribution', 'Vous avez une nouvelle ' . mb_strtolower(typeActiviteLabel($data['Types'])) . ' : "' . $data['Libelle'] . '".', '/tache_detail.php?id=' . $newId);
            if (trim((string)$data['Observation']) !== '') {
                notifierObservationActivite($pdo, $newId, (int)$data['ID_agent_dpip'], $respId, $user['Prenom'] . ' ' . $user['Nom'], $data['Libelle']);
            }

            $agentStmt = $pdo->prepare('SELECT * FROM agent_dpip WHERE ID_agent_dpip = ?');
            $agentStmt->execute([$data['ID_agent_dpip']]);
            $agentInfo = $agentStmt->fetch();
            $activiteInfo = ['Libelle' => $data['Libelle'], 'Date_fin' => $data['Date_fin'], 'Types' => $data['Types']];
            mailNouvelleTache($agentInfo, $activiteInfo);

            flash('success', typeActiviteLabel($data['Types']) . ' attribuée avec succès.');
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
        <?php if (empty($agents) && !$edition): ?>
            <div class="alert alert-warning">Vous n'avez aucun agent actif rattaché. Contactez l'administrateur avant d'attribuer une activité.</div>
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
                            <label class="form-label small fw-semibold">Agent en charge *</label>
                            <select name="ID_agent_dpip" data-exec-select class="form-select" required>
                                <option value="">— Choisir —</option>
                                <?php foreach ($agents as $a): ?>
                                    <option value="<?= $a['ID_agent_dpip'] ?>" <?= $data['ID_agent_dpip'] == $a['ID_agent_dpip'] ? 'selected' : '' ?>>
                                        <?= h($a['Prenom'] . ' ' . $a['Nom']) ?><?= $a['profils'] ? ' — ' . h($a['profils']) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php include __DIR__ . '/../includes/champ_cause_reafectation.php'; ?>
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
                        <button type="submit" class="btn btn-primary" <?= empty($agents) && !$edition ? 'disabled' : '' ?>>
                            <i class="bi bi-check2"></i> <?= $edition ? 'Enregistrer' : 'Attribuer' ?>
                        </button>
                        <a href="<?= BASE_URL ?>/responsable/activites.php" class="btn btn-light border">Annuler</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
