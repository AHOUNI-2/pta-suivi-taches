<?php
require_once __DIR__ . '/../includes/bootstrap.php';
$directeur = requireDirecteurAccess($pdo);
$dirId = (int)$directeur['ID_agent_dpip'];
$acteur = realUser();
$acteurId = (int)$acteur['ID_agent_dpip'];
$parSecretaire = $acteurId !== $dirId; // une secrétaire agit pour le directeur

$id = (int)get('id');
$edition = $id > 0;
$pageTitle = $edition ? 'Modifier une activité déléguée' : 'Déléguer une activité';

$errors = [];
$data = [
    'Libelle' => '', 'Observation' => '', 'Types' => 'activite', 'Produit' => '', 'pere_ID' => '',
    'Date_debut' => date('Y-m-d'), 'Date_fin' => '', 'ID_agent_dpip' => '',
    'ID_departement' => '', 'Priorite' => 'moyenne', 'Statut' => 'a_faire',
];

if ($edition) {
    $stmt = $pdo->prepare('SELECT * FROM activites WHERE ID__activites = ?');
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        flash('danger', 'Activité introuvable.');
        redirect('/directeur/activites.php');
    }
    // La direction peut modifier toutes les activités (déléguées par elle ou par un responsable).
    $data = array_merge($data, [
        'Libelle' => $existing['Libelle'],
        'Observation' => $existing['Observation'] ?? '',
        'Types' => $existing['Types'],
        'Produit' => $existing['Produit'] ?? '',
        'pere_ID' => (string)($existing['pere_ID'] ?? ''),
        'Date_debut' => $existing['Date_debut'],
        'Date_fin' => $existing['Date_fin'],
        'ID_agent_dpip' => (string)$existing['ID_agent_dpip'],
        'ID_departement' => (string)($existing['ID_departement'] ?? ''),
        'Priorite' => $existing['Priorite'],
        'Statut' => $existing['Statut'],
    ]);
}

// Exécutants possibles : agents ET responsables (sous-directeurs) actifs.
$executants = $pdo->query(
    "SELECT ag.ID_agent_dpip, ag.Nom, ag.Prenom, ag.role, ag.type_responsable, ag.est_responsable_service, ag.est_agent, ag.ID_departement, ag.Profil AS profils
     FROM agent_dpip ag
     WHERE (ag.role IN ('agent','responsable') OR ag.est_responsable_service = 1 OR ag.est_agent = 1) AND ag.statut_compte = 'actif'
     ORDER BY FIELD(ag.role,'responsable','agent'), ag.Nom"
)->fetchAll();
$departements = $pdo->query('SELECT * FROM departement ORDER BY Libelle')->fetchAll();
$peres = candidatsPereActivite($pdo, $edition ? $id : null);
$idsExecutants = array_column($executants, null, 'ID_agent_dpip');
$causesReafectation = causesReafectation($pdo);
$idsCauses = array_column($causesReafectation, null, 'id_cause');
$execActuelId = ($edition && !empty($existing)) ? (int)$existing['ID_agent_dpip'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    foreach (['Libelle', 'Observation', 'Types', 'Produit', 'pere_ID', 'Date_debut', 'Date_fin', 'ID_agent_dpip', 'ID_departement', 'Priorite', 'Statut'] as $f) {
        $data[$f] = post($f, $data[$f]);
    }
    $data['id_cause'] = post('id_cause');
    $data['cause_commentaire'] = post('cause_commentaire');
    $data['Priorite'] = mb_substr(trim($data['Priorite']), 0, 50) ?: 'moyenne';

    $reaffectation = $edition && (int)$existing['ID_agent_dpip'] !== (int)$data['ID_agent_dpip'] && $data['ID_agent_dpip'] !== '';
    if ($reaffectation && ($data['id_cause'] === '' || !isset($idsCauses[$data['id_cause']]))) {
        $errors[] = 'Vous changez l\'exécutant : sélectionnez une cause de réaffectation.';
    }

    if ($data['Libelle'] === '') $errors[] = 'Le libellé est obligatoire.';
    if (!in_array($data['Types'], typesActivite(), true)) $errors[] = 'Type invalide.';
    [$pereId, $errPere] = validerPereActivite($pdo, $data['Types'], $data['pere_ID'], $edition ? $id : null);
    if ($errPere) $errors[] = $errPere;
    if ($data['ID_agent_dpip'] === '' || !isset($idsExecutants[$data['ID_agent_dpip']])) $errors[] = 'Veuillez sélectionner un exécutant valide.';
    if ($data['Date_debut'] === '' || $data['Date_fin'] === '') $errors[] = 'Les dates de début et de fin sont obligatoires.';
    elseif (strtotime($data['Date_fin']) < strtotime($data['Date_debut'])) $errors[] = 'La date de fin doit être postérieure à la date de début.';
    if ($edition && !in_array($data['Statut'], ['a_faire', 'en_cours', 'en_attente', 'termine', 'en_retard', 'annule'], true)) $errors[] = 'Statut invalide.';

    if (!$errors) {
        $deptId = $data['ID_departement'] !== '' ? (int)$data['ID_departement'] : (int)($idsExecutants[$data['ID_agent_dpip']]['ID_departement'] ?? 0);
        $auteurTexte = $parSecretaire
            ? 'la Direction (' . $acteur['Prenom'] . ' ' . $acteur['Nom'] . ', secrétaire)'
            : 'la Direction';
        try {
            $execStmt = $pdo->prepare('SELECT * FROM agent_dpip WHERE ID_agent_dpip = ?');
            $execStmt->execute([$data['ID_agent_dpip']]);
            $execInfo = $execStmt->fetch();
            $mailData = ['Libelle' => $data['Libelle'], 'Date_fin' => $data['Date_fin'], 'Types' => $data['Types']];

            if ($edition) {
                $pdo->prepare(
                    'UPDATE activites SET Libelle=?, Observation=?, Types=?, Produit=?, pere_ID=?, Date_debut=?, Date_fin=?,
                            ID_agent_dpip=?, ID_departement=?, Priorite=?, Statut=? WHERE ID__activites=?'
                )->execute([
                    $data['Libelle'], $data['Observation'] ?: null, $data['Types'], $data['Produit'] ?: null, $pereId,
                    $data['Date_debut'], $data['Date_fin'], $data['ID_agent_dpip'],
                    $deptId ?: null, $data['Priorite'], $data['Statut'], $id,
                ]);
                majProgression($pdo, $id);
                if ((int)($existing['pere_ID'] ?? 0) !== (int)($pereId ?? 0)) {
                    majProgression($pdo, (int)($existing['pere_ID'] ?? 0));
                }
                logJournal($pdo, $id, $acteurId, 'modification', typeActiviteLabel($data['Types']) . ' modifiée par ' . $auteurTexte);

                $execAChange = (int)$existing['ID_agent_dpip'] !== (int)$data['ID_agent_dpip'];
                if ($execAChange) {
                    $idCause = isset($idsCauses[$data['id_cause']]) ? (int)$data['id_cause'] : null;
                    $causeTxt = $idCause ? ($idsCauses[$data['id_cause']]['libelle'] ?? '') : '';
                    enregistrerReafectation($pdo, $id, (int)$existing['ID_agent_dpip'], (int)$data['ID_agent_dpip'], $idCause, $acteurId, $data['cause_commentaire']);
                    logJournal($pdo, $id, $acteurId, 'reafectation', 'Réaffectée de ' . nomAgent($pdo, (int)$existing['ID_agent_dpip']) . ' à ' . $execInfo['Prenom'] . ' ' . $execInfo['Nom'] . ($causeTxt ? ' — cause : ' . $causeTxt : ''));
                    createNotification($pdo, (int)$data['ID_agent_dpip'], 'Nouvelle attribution', 'La direction vous confie une ' . mb_strtolower(typeActiviteLabel($data['Types'])) . ' : "' . $data['Libelle'] . '".', '/tache_detail.php?id=' . $id);
                    if ((int)$existing['ID_agent_dpip'] > 0) {
                        createNotification($pdo, (int)$existing['ID_agent_dpip'], 'Activité réaffectée', 'L\'activité "' . $data['Libelle'] . '" ne vous est plus attribuée' . ($causeTxt ? ' (' . $causeTxt . ')' : '') . '.', '/tache_detail.php?id=' . $id);
                    }
                    mailNouvelleTache($execInfo, $mailData);
                } else {
                    createNotification($pdo, (int)$data['ID_agent_dpip'], 'Activité mise à jour', 'La direction a modifié "' . $data['Libelle'] . '".', '/tache_detail.php?id=' . $id);
                    mailActiviteModifiee($execInfo, $mailData);
                }
                if (trim((string)$data['Observation']) !== '' && trim((string)$data['Observation']) !== trim((string)($existing['Observation'] ?? ''))) {
                    notifierObservationActivite($pdo, $id, (int)$data['ID_agent_dpip'], $acteurId, $auteurTexte, $data['Libelle']);
                }
                if ($parSecretaire) {
                    createNotification($pdo, $dirId, 'Activité modifiée par votre secrétaire', $acteur['Prenom'] . ' ' . $acteur['Nom'] . ' a modifié "' . $data['Libelle'] . '" en votre nom.', '/tache_detail.php?id=' . $id);
                }
                flash('success', 'Activité mise à jour.');
                redirect('/tache_detail.php?id=' . $id);
            }

            $pdo->prepare(
                'INSERT INTO activites (Libelle, Observation, Types, Produit, pere_ID, Date_debut, Date_fin, ID_agent_dpip, ID_responsable, ID_departement, Priorite)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $data['Libelle'], $data['Observation'] ?: null, $data['Types'], $data['Produit'] ?: null, $pereId,
                $data['Date_debut'], $data['Date_fin'], $data['ID_agent_dpip'], $dirId, $deptId ?: null, $data['Priorite'],
            ]);
            $newId = (int)$pdo->lastInsertId();

            majProgression($pdo, $newId);
            logJournal($pdo, $newId, $acteurId, 'creation', typeActiviteLabel($data['Types']) . ' déléguée par ' . $auteurTexte);
            createNotification($pdo, (int)$data['ID_agent_dpip'], 'Nouvelle activité de la direction', 'La direction vous confie une ' . mb_strtolower(typeActiviteLabel($data['Types'])) . ' : "' . $data['Libelle'] . '".', '/tache_detail.php?id=' . $newId);
            if (trim((string)$data['Observation']) !== '') {
                notifierObservationActivite($pdo, $newId, (int)$data['ID_agent_dpip'], $acteurId, $auteurTexte, $data['Libelle']);
            }
            if ($parSecretaire) {
                createNotification($pdo, $dirId, 'Activité déléguée par votre secrétaire', $acteur['Prenom'] . ' ' . $acteur['Nom'] . ' a délégué "' . $data['Libelle'] . '" en votre nom.', '/tache_detail.php?id=' . $newId);
            }
            mailNouvelleTache($execInfo, $mailData);

            flash('success', typeActiviteLabel($data['Types']) . ' déléguée avec succès.');
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
        <?php if (empty($executants)): ?>
            <div class="alert alert-warning">Aucun agent ni responsable actif : impossible de déléguer une activité pour le moment.</div>
        <?php endif; ?>
        <div class="card">
            <div class="card-header">
                <?= $edition ? 'Modifier l\'activité déléguée' : 'Déléguer une activité, sous-activité ou tâche' ?>
                <div class="small text-muted">Attribuée par : <strong><?= h($directeur['Prenom'] . ' ' . $directeur['Nom']) ?></strong> (Directeur)<?= $parSecretaire ? ' — saisie par ' . h($acteur['Prenom'] . ' ' . $acteur['Nom']) : '' ?></div>
            </div>
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
                            <label class="form-label small fw-semibold">Exécutant * (Responsable Service / Sous-directeur ou agent)</label>
                            <select name="ID_agent_dpip" data-exec-select class="form-select" required>
                                <option value="">— Choisir —</option>
                                <?php foreach ($executants as $ex): ?>
                                    <?php
                                    $prefixe = '';
                                    if (($ex['type_responsable'] ?? '') === 'sous_directeur') $prefixe = '[Sous-directeur] ';
                                    elseif ($ex['role'] === 'responsable' || estResponsableService($ex)) $prefixe = '[Resp. Service] ';
                                    ?>
                                    <option value="<?= $ex['ID_agent_dpip'] ?>" <?= $data['ID_agent_dpip'] == $ex['ID_agent_dpip'] ? 'selected' : '' ?>>
                                        <?= h($prefixe . $ex['Prenom'] . ' ' . $ex['Nom']) ?><?= $ex['role'] !== 'agent' && $ex['role'] !== 'responsable' ? ' (' . h(roleLabel($ex['role'])) . ')' : '' ?><?= $ex['profils'] ? ' — ' . h($ex['profils']) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php include __DIR__ . '/../includes/champ_cause_reafectation.php'; ?>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Département</label>
                            <select name="ID_departement" class="form-select">
                                <option value="">— Celui de l'exécutant —</option>
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
                        <button type="submit" class="btn btn-primary" <?= empty($executants) ? 'disabled' : '' ?>>
                            <i class="bi bi-check2"></i> <?= $edition ? 'Enregistrer' : 'Déléguer' ?>
                        </button>
                        <a href="<?= BASE_URL ?>/directeur/activites.php" class="btn btn-light border">Annuler</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
