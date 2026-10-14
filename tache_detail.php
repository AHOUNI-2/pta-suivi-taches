<?php
require_once __DIR__ . '/includes/bootstrap.php';
$user = requireLogin();

$id = (int)get('id');
$stmt = $pdo->prepare(
    "SELECT a.*, ag.Nom AS ag_nom, ag.Prenom AS ag_prenom, ag.Mail AS ag_mail, ag.Mail_Pro AS ag_mail_pro,
            r.Nom AS resp_nom, r.Prenom AS resp_prenom, r.Mail AS resp_mail, r.Mail_Pro AS resp_mail_pro,
            d.Libelle AS dept_libelle, d.Nom_court AS dept_nom
     FROM activites a
     JOIN agent_dpip ag ON ag.ID_agent_dpip = a.ID_agent_dpip
     JOIN agent_dpip r ON r.ID_agent_dpip = a.ID_responsable
     LEFT JOIN departement d ON d.id_departement = a.ID_departement
     WHERE a.ID__activites = ?"
);
$stmt->execute([$id]);
$tache = $stmt->fetch();

// Rattachement (parent) et enfants directs
$tacheParent = null;
$tacheEnfants = [];
if ($tache) {
    if (!empty($tache['pere_ID'])) {
        $pStmt = $pdo->prepare('SELECT ID__activites, Libelle, Types FROM activites WHERE ID__activites = ?');
        $pStmt->execute([$tache['pere_ID']]);
        $tacheParent = $pStmt->fetch() ?: null;
    }
    $eStmt = $pdo->prepare(
        "SELECT a.ID__activites, a.Libelle, a.Types, a.Statut, a.Progression, a.Date_fin,
                ag.Prenom AS ag_prenom, ag.Nom AS ag_nom
         FROM activites a JOIN agent_dpip ag ON ag.ID_agent_dpip = a.ID_agent_dpip
         WHERE a.pere_ID = ? ORDER BY FIELD(a.Types,'activite','sous-activite','tache'), a.Date_fin"
    );
    $eStmt->execute([$id]);
    $tacheEnfants = $eStmt->fetchAll();
}

if (!$tache) {
    flash('danger', 'Tâche introuvable.');
    redirect(dashboardUrlForRole($user['role']));
}

// Contexte : une secrétaire peut agir pour un directeur.
$directeurCtx = actingDirecteur($pdo);
$roleAssignateur = $directeurCtx ? 'directeur' : $user['role'];
$idAssignateurEffectif = $directeurCtx ? (int)$directeurCtx['ID_agent_dpip'] : (int)$user['ID_agent_dpip'];

$estExecutant  = (int)$tache['ID_agent_dpip'] === (int)$user['ID_agent_dpip'];           // celui qui exécute (agent ou sous-directeur)
$estAssignateur = in_array($roleAssignateur, ['responsable', 'directeur'], true)
                  && (int)$tache['ID_responsable'] === $idAssignateurEffectif;             // celui qui a attribué (ou sa secrétaire)
$estAdmin = $user['role'] === 'admin';
$estDirecteurGlobal = $roleAssignateur === 'directeur';                                    // le directeur voit toutes les activités

// Alias rétro-compatibles utilisés plus bas dans la page
$estAgent = $estExecutant;
$estResponsable = $estAssignateur;

// L'onglet « Historique » n'est pas montré aux agents.
$voitHistorique = ($user['role'] !== 'agent');
$peutFaireRetour = $estAssignateur || $estAdmin || $estDirecteurGlobal;   // responsable / admin / direction

if (!$estExecutant && !$estAssignateur && !$estAdmin && !$estDirecteurGlobal) {
    flash('danger', "Vous n'avez pas accès à cette activité.");
    redirect(dashboardUrlForRole($user['role']));
}

$pageTitle = 'Détail — ' . typeActiviteLabel($tache['Types']);
$backUrl = dashboardUrlForRole($user['role']);
if ($estDirecteurGlobal) $backUrl = '/directeur/activites.php';
if ($estAdmin) $backUrl = '/admin/activites.php';
if ($estExecutant && $user['role'] === 'responsable') $backUrl = '/responsable/activites_recues.php';
if ($estExecutant && $user['role'] === 'agent') $backUrl = '/agent/mes_taches.php';
if ($estAssignateur && $user['role'] === 'responsable') $backUrl = '/responsable/activites.php';

$agentInfo = ['Prenom' => $tache['ag_prenom'], 'Nom' => $tache['ag_nom'], 'Mail' => $tache['ag_mail'], 'Mail_Pro' => $tache['ag_mail_pro']];
$respInfo = ['Prenom' => $tache['resp_prenom'], 'Nom' => $tache['resp_nom'], 'Mail' => $tache['resp_mail'], 'Mail_Pro' => $tache['resp_mail_pro']];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = post('action');
    try {
        if ($action === 'nouveau_compte_rendu' && $estAgent) {
            $contenu = post('Contenu');
            $statut = post('Statut_activite');
            if ($contenu === '') throw new RuntimeException('Veuillez décrire l\'avancement réalisé.');
            if (!in_array($statut, ['a_faire', 'en_cours', 'en_attente', 'termine', 'annule'], true)) {
                throw new RuntimeException('Statut invalide.');
            }

            $pieceJointe = null;
            if (!empty($_FILES['piece_jointe']['name'])) {
                $up = handleFileUpload($_FILES['piece_jointe'], UPLOAD_PATH_COMPTES_RENDUS, 'cr_' . $id);
                $pieceJointe = $up['stored_name'] ?? null;
            }

            $pdo->beginTransaction();
            // L'agent ne renseigne que le statut ; progression (et statut d'une activité
            // composée) sont recalculés automatiquement.
            $pdo->prepare('UPDATE activites SET Statut = ? WHERE ID__activites = ?')->execute([$statut, $id]);
            majProgression($pdo, $id);
            $etat = $pdo->query('SELECT Statut, Progression FROM activites WHERE ID__activites = ' . $id)->fetch();
            $progression = (int)$etat['Progression'];
            $statutFinal = (string)$etat['Statut'];
            $pdo->prepare('INSERT INTO comptes_rendus (ID_activites, ID_agent_dpip, Contenu, Progression, Statut_activite, Piece_jointe) VALUES (?,?,?,?,?,?)')
                ->execute([$id, $user['ID_agent_dpip'], $contenu, $progression, $statutFinal, $pieceJointe]);
            logJournal($pdo, $id, (int)$user['ID_agent_dpip'], 'compte_rendu', "Compte rendu soumis — statut : " . statutLabel($statutFinal) . ", progression : {$progression}%");
            $pdo->commit();

            createNotification($pdo, (int)$tache['ID_responsable'], 'Nouveau compte rendu', $agentInfo['Prenom'] . ' ' . $agentInfo['Nom'] . ' a soumis un compte rendu pour "' . $tache['Libelle'] . '".', '/tache_detail.php?id=' . $id);
            mailNouveauCompteRendu($respInfo, $tache, $agentInfo);

            flash('success', 'Votre compte rendu a été enregistré.');
            redirect('/tache_detail.php?id=' . $id . '#comptes-rendus');
        }

        if ($action === 'ajouter_livrable' && $estAgent) {
            if (empty($_FILES['fichier']['name'])) throw new RuntimeException('Veuillez sélectionner un fichier.');
            $up = handleFileUpload($_FILES['fichier'], UPLOAD_PATH_LIVRABLES, 'liv_' . $id);
            if (!$up) throw new RuntimeException('Le fichier n\'a pas pu être enregistré.');
            $libelle = post('libelle') ?: $up['original_name'];
            $pdo->prepare('INSERT INTO livrables (libelle, Piece_jointe, ID_activites, ID_agent_dpip, Commentaire) VALUES (?,?,?,?,?)')
                ->execute([$libelle, $up['stored_name'], $id, $user['ID_agent_dpip'], post('commentaire') ?: null]);
            logJournal($pdo, $id, (int)$user['ID_agent_dpip'], 'livrable', 'Livrable déposé : ' . $libelle);
            createNotification($pdo, (int)$tache['ID_responsable'], 'Nouveau livrable', $agentInfo['Prenom'] . ' a déposé un livrable pour "' . $tache['Libelle'] . '".', '/tache_detail.php?id=' . $id);
            flash('success', 'Livrable ajouté avec succès.');
            redirect('/tache_detail.php?id=' . $id . '#livrables');
        }

        if ($action === 'ajouter_besoin' && $estAgent) {
            $libelle = post('libelle');
            $type = post('type');
            $estimation = (int)post('estimation');
            if ($libelle === '') throw new RuntimeException('Veuillez décrire le besoin.');
            $profilHumain = ($type === 'humain' && post('profil_humain') !== '') ? post('profil_humain') : null;
            $pdo->prepare('INSERT INTO besions (libelle, Type, profil_humain, Estimation_financiere, ID_activites) VALUES (?,?,?,?,?)')
                ->execute([$libelle, $type, $profilHumain, $estimation, $id]);
            logJournal($pdo, $id, (int)$user['ID_agent_dpip'], 'besoin', 'Besoin exprimé : ' . $libelle);
            createNotification($pdo, (int)$tache['ID_responsable'], 'Nouveau besoin exprimé', $agentInfo['Prenom'] . ' a exprimé un besoin pour "' . $tache['Libelle'] . '".', '/tache_detail.php?id=' . $id);
            flash('success', 'Besoin ajouté avec succès.');
            redirect('/tache_detail.php?id=' . $id . '#besoins');
        }

        if ($action === 'ajouter_retour' && $peutFaireRetour) {
            $message = post('message');
            if ($message === '') throw new RuntimeException('Veuillez saisir votre retour.');
            $contexte = post('contexte');
            if (!in_array($contexte, ['sans_reaction', 'en_cours', 'termine', 'autre'], true)) {
                $nbCR = (int)$pdo->query('SELECT COUNT(*) FROM comptes_rendus WHERE ID_activites = ' . $id)->fetchColumn();
                $contexte = contexteRetourAuto((string)$tache['Statut'], $nbCR);
            }
            $pdo->prepare(
                'INSERT INTO retour_responsable (ID_activites, ID_responsable, ID_agent, contexte, statut_activite, message)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$id, (int)$user['ID_agent_dpip'], (int)$tache['ID_agent_dpip'], $contexte, $tache['Statut'], $message]);
            logJournal($pdo, $id, (int)$user['ID_agent_dpip'], 'retour', 'Retour du responsable — ' . contexteRetourLabel($contexte));
            createNotification(
                $pdo,
                (int)$tache['ID_agent_dpip'],
                'Retour de votre responsable',
                $user['Prenom'] . ' ' . $user['Nom'] . ' vous a fait un retour sur "' . $tache['Libelle'] . '".',
                '/tache_detail.php?id=' . $id . '#retours'
            );
            flash('success', "Retour envoyé à l'agent.");
            redirect('/tache_detail.php?id=' . $id . '#retours');
        }

        if ($action === 'modifier_tache' && ($estResponsable || $estAdmin)) {
            $libelle = post('Libelle');
            $observation = post('Observation');
            $produit = post('Produit');
            $types = post('Types');
            $dateDebut = post('Date_debut');
            $dateFin = post('Date_fin');
            $priorite = mb_substr(post('Priorite'), 0, 50) ?: 'moyenne';
            $statut = post('Statut');
            if ($libelle === '' || $dateDebut === '' || $dateFin === '') throw new RuntimeException('Libellé et dates sont obligatoires.');
            if (strtotime($dateFin) < strtotime($dateDebut)) throw new RuntimeException('La date de fin doit être postérieure à la date de début.');
            // Une activité de tête n'a pas de parent ; sinon on conserve le rattachement existant
            // (il se modifie via le formulaire complet « Modifier »).
            if ($types === 'activite') {
                $pdo->prepare('UPDATE activites SET pere_ID = NULL WHERE ID__activites = ?')->execute([$id]);
            }
            $pdo->prepare('UPDATE activites SET Libelle=?, Observation=?, Produit=?, Types=?, Date_debut=?, Date_fin=?, Priorite=?, Statut=? WHERE ID__activites=?')
                ->execute([$libelle, $observation, $produit, $types, $dateDebut, $dateFin, $priorite, $statut, $id]);
            majProgression($pdo, $id);
            logJournal($pdo, $id, (int)$user['ID_agent_dpip'], 'modification', typeActiviteLabel($types) . ' modifiée par ' . $user['Prenom'] . ' ' . $user['Nom']);
            createNotification($pdo, (int)$tache['ID_agent_dpip'], 'Activité mise à jour', typeActiviteLabel($types) . ' "' . $libelle . '" a été modifiée.', '/tache_detail.php?id=' . $id);
            // Observation ajoutée / modifiée sur l'activité : la remonter à l'exécutant dans l'onglet Observations.
            $ancienneObs = trim((string)($tache['Observation'] ?? ''));
            if (trim($observation) !== '' && trim($observation) !== $ancienneObs) {
                notifierObservationActivite($pdo, $id, (int)$tache['ID_agent_dpip'], (int)$user['ID_agent_dpip'], $user['Prenom'] . ' ' . $user['Nom'], $libelle);
            }
            mailActiviteModifiee($agentInfo, ['Libelle' => $libelle, 'Date_fin' => $dateFin, 'Types' => $types]);
            flash('success', 'Activité mise à jour.');
            redirect('/tache_detail.php?id=' . $id);
        }

        if ($action === 'marquer_lu' && $estResponsable) {
            $crId = (int)post('cr_id');
            $pdo->prepare('UPDATE comptes_rendus SET Lu_par_responsable = 1, Date_lecture = NOW() WHERE id_compte_rendu = ? AND ID_activites = ?')
                ->execute([$crId, $id]);
            redirect('/tache_detail.php?id=' . $id . '#comptes-rendus');
        }

        if ($action === 'supprimer_tache' && ($estResponsable || $estAdmin)) {
            $pereSupprime = !empty($tache['pere_ID']) ? (int)$tache['pere_ID'] : null;
            $pdo->prepare('DELETE FROM activites WHERE ID__activites = ?')->execute([$id]);
            majProgression($pdo, $pereSupprime);   // le parent perd un enfant : sa progression change
            flash('success', $tacheEnfants
                ? typeActiviteLabel($tache['Types']) . ' supprimée, ainsi que ' . count($tacheEnfants) . ' élément(s) rattaché(s).'
                : typeActiviteLabel($tache['Types']) . ' supprimée.');
            redirect($backUrl);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('danger', $e->getMessage());
        redirect('/tache_detail.php?id=' . $id);
    }
}

$comptesRendus = $pdo->prepare('SELECT * FROM comptes_rendus WHERE ID_activites = ? ORDER BY Date_rendu DESC');
$comptesRendus->execute([$id]);
$comptesRendus = $comptesRendus->fetchAll();

$livrables = $pdo->prepare('SELECT l.*, u.Nom, u.Prenom FROM livrables l LEFT JOIN agent_dpip u ON u.ID_agent_dpip = l.ID_agent_dpip WHERE ID_activites = ? ORDER BY Date_depot DESC');
$livrables->execute([$id]);
$livrables = $livrables->fetchAll();

$besoins = $pdo->prepare('SELECT b.* FROM besions b WHERE b.ID_activites = ? ORDER BY b.Date_creation DESC');
$besoins->execute([$id]);
$besoins = $besoins->fetchAll();

// Observations affichées dans l'onglet « Observations » : on regroupe
//  1) les observations horodatées saisies dans l'onglet (table observation) ;
//  2) la note portée directement sur l'activité (champ activites.Observation),
//     saisie par le responsable / l'admin via « Modifier ».
$observations = $pdo->prepare(
    'SELECT o.libelle AS texte, o.Date_creation, u.Nom, u.Prenom, "remarque" AS source
     FROM observation o LEFT JOIN agent_dpip u ON u.ID_agent_dpip = o.ID_agent_dpip
     WHERE o.id_activites = ?'
);
$observations->execute([$id]);
$observations = $observations->fetchAll();
if (trim((string)($tache['Observation'] ?? '')) !== '') {
    $observations[] = [
        'texte'         => $tache['Observation'],
        'Date_creation' => $tache['Date_modification'],
        'Nom'           => $tache['resp_nom'],
        'Prenom'        => $tache['resp_prenom'],
        'source'        => 'activite',
    ];
}
usort($observations, fn($a, $b) => strtotime((string)$b['Date_creation']) <=> strtotime((string)$a['Date_creation']));

// Historique de cet élément (survit déjà aux réaffectations : même ID_activites).
$journal = $pdo->prepare('SELECT j.*, u.Nom, u.Prenom FROM journal_activites j LEFT JOIN agent_dpip u ON u.ID_agent_dpip = j.ID_agent_dpip WHERE j.ID_activites = ? ORDER BY j.Date_action DESC, j.id_journal DESC');
$journal->execute([$id]);
$journal = $journal->fetchAll();

// Historique complet du dossier (activité racine + tous les éléments rattachés).
$racineId = activiteRacine($pdo, $id);
$journalDossier = $pdo->prepare(
    'SELECT j.*, u.Nom, u.Prenom, act.Libelle AS item_libelle, act.Types AS item_types
     FROM journal_activites j
     LEFT JOIN agent_dpip u  ON u.ID_agent_dpip  = j.ID_agent_dpip
     LEFT JOIN activites  act ON act.ID__activites = j.ID_activites
     WHERE j.id_enregistrement = ?
     ORDER BY j.Date_action DESC, j.id_journal DESC'
);
$journalDossier->execute([$racineId]);
$journalDossier = $journalDossier->fetchAll();

$reafectations = reafectationsActivite($pdo, $id);

// Retours du responsable. L'agent qui ouvre la fiche marque les siens comme lus.
if ($estExecutant) {
    $pdo->prepare('UPDATE retour_responsable SET lu = 1, date_lecture = NOW()
                   WHERE ID_activites = ? AND ID_agent = ? AND lu = 0')
        ->execute([$id, (int)$user['ID_agent_dpip']]);
}
$retours = $pdo->prepare(
    'SELECT r.*, u.Nom AS resp_nom, u.Prenom AS resp_prenom
     FROM retour_responsable r
     LEFT JOIN agent_dpip u ON u.ID_agent_dpip = r.ID_responsable
     WHERE r.ID_activites = ?
     ORDER BY r.Date_creation DESC, r.id_retour DESC'
);
$retours->execute([$id]);
$retours = $retours->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<a href="<?= BASE_URL . h($backUrl) ?>" class="text-muted small d-inline-flex align-items-center gap-1 mb-3"><i class="bi bi-arrow-left"></i> Retour</a>

<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between flex-wrap gap-2 mb-2">
            <div>
                <span class="badge <?= statutBadgeClass($tache['Statut']) ?>"><?= h(statutLabel($tache['Statut'], (int)$tache['Progression'])) ?></span>
                <span class="badge <?= prioriteBadgeClass($tache['Priorite']) ?>">Priorité <?= h(mb_strtolower(prioriteLabel($tache['Priorite']))) ?></span>
                <span class="badge <?= typeActiviteBadgeClass($tache['Types']) ?>"><?= h(typeActiviteLabel($tache['Types'])) ?></span>
                <?php if ($tache['dept_nom']): ?><span class="badge bg-light text-dark border"><?= h($tache['dept_nom']) ?></span><?php endif; ?>
            </div>
            <?php if ($estResponsable || $estAdmin): ?>
                <div class="d-flex gap-2">
                    <?php if ($estResponsable || $estAdmin): ?>
                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalEdit"><i class="bi bi-pencil"></i> Modifier</button>
                    <?php endif; ?>
                    <form method="post" data-confirm="Supprimer définitivement cette entrée et toutes ses données associées<?= $tacheEnfants ? ' (ainsi que les ' . count($tacheEnfants) . ' élément(s) qui lui sont rattachés)' : '' ?> ?">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="supprimer_tache">
                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Supprimer</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
        <h2 class="h4 fw-bold mb-1"><?= h($tache['Libelle']) ?></h2>
        <?php if ($tacheParent): ?>
            <p class="small mb-2">
                <i class="bi bi-diagram-3"></i> Rattachée à :
                <a href="<?= BASE_URL ?>/tache_detail.php?id=<?= $tacheParent['ID__activites'] ?>">
                    <span class="badge <?= typeActiviteBadgeClass($tacheParent['Types']) ?>"><?= h(typeActiviteLabel($tacheParent['Types'])) ?></span>
                    <?= h($tacheParent['Libelle']) ?>
                </a>
            </p>
        <?php endif; ?>
        <?php if ($tache['Observation']): ?>
            <div class="mb-3">
                <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.03em;">Observation</div>
                <p class="text-muted mb-0"><?= nl2br(h($tache['Observation'])) ?></p>
            </div>
        <?php endif; ?>

        <div class="row g-3 mb-3">
            <div class="col-md-3 col-6">
                <div class="text-muted small">Agent en charge</div>
                <div class="fw-semibold"><?= h($tache['ag_prenom'] . ' ' . $tache['ag_nom']) ?></div>
            </div>
            <div class="col-md-3 col-6">
                <div class="text-muted small">Attribuée par</div>
                <div class="fw-semibold"><?= h($tache['resp_prenom'] . ' ' . $tache['resp_nom']) ?></div>
            </div>
            <div class="col-md-3 col-6">
                <div class="text-muted small">Période</div>
                <div class="fw-semibold"><?= formatDate($tache['Date_debut']) ?> → <?= formatDate($tache['Date_fin']) ?></div>
            </div>
            <div class="col-md-3 col-6">
                <div class="text-muted small">Produit attendu</div>
                <div class="fw-semibold"><?= h($tache['Produit'] ?: '—') ?></div>
            </div>
        </div>

        <div class="d-flex justify-content-between small mb-1">
            <span class="text-muted">Progression</span>
            <span class="fw-semibold"><?= (int)$tache['Progression'] ?>%</span>
        </div>
        <div class="progress"><div class="progress-bar bg-success" style="width:<?= (int)$tache['Progression'] ?>%"></div></div>
        <?php if (estEnRetard($tache['Date_fin'], $tache['Statut'])): ?>
            <div class="text-danger small mt-2"><i class="bi bi-exclamation-triangle"></i> Échéance dépassée de <?= abs(joursRestants($tache['Date_fin'])) ?> jour(s)</div>
        <?php elseif (!in_array($tache['Statut'], ['termine', 'annule'], true)): ?>
            <div class="text-muted small mt-2"><i class="bi bi-clock"></i> <?= joursRestants($tache['Date_fin']) ?> jour(s) restant(s)</div>
        <?php endif; ?>
    </div>
</div>

<?php if ($tacheEnfants): ?>
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-diagram-3"></i> Éléments rattachés (<?= count($tacheEnfants) ?>)</div>
    <div class="table-responsive">
        <table class="table mb-0">
            <thead><tr><th>Type</th><th>Libellé</th><th>Exécutant</th><th>Échéance</th><th>Progression</th><th>Statut</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($tacheEnfants as $e): ?>
                <tr>
                    <td><span class="badge <?= typeActiviteBadgeClass($e['Types']) ?>"><?= h(typeActiviteLabel($e['Types'])) ?></span></td>
                    <td><?= h($e['Libelle']) ?></td>
                    <td><?= h($e['ag_prenom'] . ' ' . $e['ag_nom']) ?></td>
                    <td class="<?= estEnRetard($e['Date_fin'], $e['Statut']) ? 'text-danger fw-semibold' : '' ?>"><?= formatDate($e['Date_fin']) ?></td>
                    <td style="min-width:100px;">
                        <div class="progress"><div class="progress-bar bg-success" style="width:<?= (int)$e['Progression'] ?>%"></div></div>
                        <span class="small text-muted"><?= (int)$e['Progression'] ?>%</span>
                    </td>
                    <td><span class="badge <?= statutBadgeClass($e['Statut']) ?>"><?= h(statutLabel($e['Statut'], (int)$e['Progression'])) ?></span></td>
                    <td><a href="<?= BASE_URL ?>/tache_detail.php?id=<?= $e['ID__activites'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#comptes-rendus">Comptes rendus <span class="badge bg-secondary"><?= count($comptesRendus) ?></span></a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#livrables">Livrables <span class="badge bg-secondary"><?= count($livrables) ?></span></a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#besoins">Besoins <span class="badge bg-secondary"><?= count($besoins) ?></span></a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#observations">Observations <span class="badge bg-secondary"><?= count($observations) ?></span></a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#retours">Retours <span class="badge bg-secondary"><?= count($retours) ?></span></a></li>
    <?php if ($reafectations): ?>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#reafectations">Réaffectations <span class="badge bg-secondary"><?= count($reafectations) ?></span></a></li>
    <?php endif; ?>
    <?php if ($voitHistorique): ?>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#historique">Historique</a></li>
    <?php endif; ?>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="comptes-rendus">
        <?php if ($estAgent): ?>
        <div class="card mb-3">
            <div class="card-header">Soumettre un compte rendu</div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="nouveau_compte_rendu">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Avancement réalisé *</label>
                        <textarea name="Contenu" class="form-control" rows="3" required placeholder="Décrivez ce qui a été fait depuis le dernier compte rendu..."></textarea>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Statut de la tâche *</label>
                            <select name="Statut_activite" class="form-select" required>
                                <option value="a_faire" <?= $tache['Statut'] === 'a_faire' ? 'selected' : '' ?>>À faire</option>
                                <option value="en_cours" <?= $tache['Statut'] === 'en_cours' ? 'selected' : '' ?>>En cours</option>
                                <option value="en_attente" <?= $tache['Statut'] === 'en_attente' ? 'selected' : '' ?>>En attente</option>
                                <option value="termine" <?= $tache['Statut'] === 'termine' ? 'selected' : '' ?>>Terminé</option>
                            </select>
                            <div class="form-text">La progression est calculée automatiquement à partir du statut et des éléments rattachés.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Pièce jointe (optionnel)</label>
                            <input type="file" name="piece_jointe" class="form-control">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-send"></i> Envoyer le compte rendu</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php foreach ($comptesRendus as $cr): ?>
            <div class="card mb-2">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <span class="badge <?= statutBadgeClass($cr['Statut_activite']) ?>"><?= h(statutLabel($cr['Statut_activite'])) ?> — <?= (int)$cr['Progression'] ?>%</span>
                        <span class="text-muted small"><?= formatDate($cr['Date_rendu'], true) ?></span>
                    </div>
                    <p class="mb-1 mt-2"><?= nl2br(h($cr['Contenu'])) ?></p>
                    <?php if ($cr['Piece_jointe']): ?>
                        <a href="<?= UPLOAD_URL_COMPTES_RENDUS . h($cr['Piece_jointe']) ?>" target="_blank" class="small"><i class="bi bi-paperclip"></i> Pièce jointe</a>
                    <?php endif; ?>
                    <?php if ($estResponsable): ?>
                        <div class="mt-2">
                            <?php if ($cr['Lu_par_responsable']): ?>
                                <span class="text-success small"><i class="bi bi-check2-all"></i> Lu le <?= formatDate($cr['Date_lecture'], true) ?></span>
                            <?php else: ?>
                                <form method="post" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="marquer_lu">
                                    <input type="hidden" name="cr_id" value="<?= $cr['id_compte_rendu'] ?>">
                                    <button class="btn btn-sm btn-outline-secondary">Marquer comme lu</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (empty($comptesRendus)): ?><p class="text-muted">Aucun compte rendu pour le moment.</p><?php endif; ?>
    </div>

    <div class="tab-pane fade" id="livrables">
        <?php if ($estAgent): ?>
        <div class="card mb-3">
            <div class="card-header">Déposer un livrable</div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="ajouter_livrable">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Libellé</label>
                            <input type="text" name="libelle" class="form-control" placeholder="Nom du document">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Fichier *</label>
                            <input type="file" name="fichier" class="form-control" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Commentaire</label>
                            <input type="text" name="commentaire" class="form-control">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-upload"></i> Déposer</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
        <div class="list-group">
            <?php foreach ($livrables as $l): ?>
                <a href="<?= UPLOAD_URL_LIVRABLES . h($l['Piece_jointe']) ?>" target="_blank" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                    <div>
                        <i class="bi bi-file-earmark-arrow-down"></i> <strong><?= h($l['libelle']) ?></strong>
                        <?php if ($l['Commentaire']): ?><div class="text-muted small"><?= h($l['Commentaire']) ?></div><?php endif; ?>
                        <div class="text-muted small">Déposé par <?= h(($l['Prenom'] ?? '') . ' ' . ($l['Nom'] ?? '')) ?></div>
                    </div>
                    <span class="text-muted small"><?= formatDate($l['Date_depot'], true) ?></span>
                </a>
            <?php endforeach; ?>
            <?php if (empty($livrables)): ?><p class="text-muted">Aucun livrable déposé.</p><?php endif; ?>
        </div>
    </div>

    <div class="tab-pane fade" id="besoins">
        <?php if ($estAgent): ?>
        <div class="card mb-3">
            <div class="card-header">Exprimer un besoin</div>
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="ajouter_besoin">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Besoin *</label>
                            <input type="text" name="libelle" class="form-control" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Type</label>
                            <select name="type" id="besoinType" class="form-select" onchange="majBesoinProfil()">
                                <option value="materiel">Matériel</option>
                                <option value="humain">Humain</option>
                                <option value="financier">Financier</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Estimation (FCFA)</label>
                            <input type="number" name="estimation" class="form-control" min="0" value="0">
                        </div>
                        <div class="col-md-6" id="besoinProfilWrap" style="display:none;">
                            <label class="form-label small fw-semibold">Profil de la personne recherchée</label>
                            <input type="text" name="profil_humain" id="besoinProfil" class="form-control" maxlength="255"
                                   placeholder="Ex. : Statisticien confirmé, informaticien...">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary mt-3"><i class="bi bi-plus-lg"></i> Ajouter</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="table"><thead><tr><th>Besoin</th><th>Type</th><th>Profil recherché</th><th>Estimation</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($besoins as $b): ?>
                <tr>
                    <td><?= h($b['libelle']) ?></td>
                    <td><span class="badge bg-light text-dark border"><?= h(ucfirst($b['Type'])) ?></span></td>
                    <td><?= $b['Type'] === 'humain' ? (!empty($b['profil_humain']) ? h($b['profil_humain']) : '<span class="text-muted">non précisé</span>') : '—' ?></td>
                    <td><?= number_format($b['Estimation_financiere'], 0, ',', ' ') ?> FCFA</td>
                    <td><?= formatDate($b['Date_creation']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($besoins)): ?><tr><td colspan="5" class="text-center text-muted py-3">Aucun besoin exprimé.</td></tr><?php endif; ?>
            </tbody></table>
        </div>
    </div>

    <div class="tab-pane fade" id="observations">
        <?php if ($estResponsable): ?>
        <p class="text-muted small mb-3"><i class="bi bi-info-circle"></i> Les observations se saisissent dans le champ <strong>Observation</strong> du formulaire de l'activité (bouton « Modifier »).</p>
        <?php endif; ?>
        <?php foreach ($observations as $o): ?>
            <div class="card mb-2">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <strong><?= h(trim(($o['Prenom'] ?? '') . ' ' . ($o['Nom'] ?? ''))) ?: 'Responsable' ?></strong>
                        <span class="text-muted small">
                            <?php if (($o['source'] ?? '') === 'activite'): ?><span class="badge bg-light text-dark border me-1">sur l'activité</span><?php endif; ?>
                            <?= formatDate($o['Date_creation'], true) ?>
                        </span>
                    </div>
                    <p class="mb-0 mt-1"><?= nl2br(h($o['texte'])) ?></p>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (empty($observations)): ?><p class="text-muted">Aucune observation.</p><?php endif; ?>
    </div>

    <div class="tab-pane fade" id="retours">
        <?php if ($peutFaireRetour): ?>
        <div class="card mb-3">
            <div class="card-header">Faire un retour à l'agent</div>
            <div class="card-body">
                <p class="small text-muted mb-2">Relancez l'agent s'il n'a pas réagi, suivez l'avancement, ou faites un retour une fois la tâche terminée. L'agent est notifié.</p>
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="ajouter_retour">
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Contexte</label>
                        <?php $autoCtx = contexteRetourAuto((string)$tache['Statut'], count($comptesRendus)); ?>
                        <select name="contexte" class="form-select form-select-sm">
                            <?php foreach (['sans_reaction', 'en_cours', 'termine', 'autre'] as $c): ?>
                                <option value="<?= $c ?>" <?= $c === $autoCtx ? 'selected' : '' ?>><?= h(contexteRetourLabel($c)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <textarea name="message" class="form-control mb-2" rows="3" required placeholder="Votre message à l'agent..."></textarea>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-send"></i> Envoyer le retour</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
        <?php foreach ($retours as $rr): ?>
            <div class="card mb-2">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                        <strong><?= h(trim(($rr['resp_prenom'] ?? '') . ' ' . ($rr['resp_nom'] ?? ''))) ?: 'Responsable' ?></strong>
                        <span class="text-muted small">
                            <span class="badge <?= contexteRetourBadge($rr['contexte']) ?>"><?= h(contexteRetourLabel($rr['contexte'])) ?></span>
                            <?php if (!$rr['lu'] && !$estExecutant): ?><span class="badge bg-warning text-dark">non lu par l'agent</span><?php endif; ?>
                            <?= formatDate($rr['Date_creation'], true) ?>
                        </span>
                    </div>
                    <p class="mb-0 mt-1"><?= nl2br(h($rr['message'])) ?></p>
                    <?php if ($rr['lu'] && $rr['date_lecture'] && !$estExecutant): ?>
                        <div class="small text-success mt-1"><i class="bi bi-check2-all"></i> Lu par l'agent le <?= formatDate($rr['date_lecture'], true) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (empty($retours)): ?><p class="text-muted">Aucun retour pour le moment.</p><?php endif; ?>
    </div>

    <?php if ($reafectations): ?>
    <div class="tab-pane fade" id="reafectations">
        <p class="text-muted small">Chaque changement d'agent en charge de cette activité, avec sa cause.</p>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Date</th><th>Ancien agent</th><th>Nouvel agent</th><th>Cause</th><th>Attribuée par</th><th>Précision</th></tr></thead>
                <tbody>
                <?php foreach ($reafectations as $rf): ?>
                    <tr>
                        <td class="text-muted small"><?= formatDate($rf['Date_creation'], true) ?></td>
                        <td><?= h(trim(($rf['ancien_prenom'] ?? '') . ' ' . ($rf['ancien_nom'] ?? ''))) ?: '<span class="text-muted">—</span>' ?></td>
                        <td class="fw-semibold"><?= h(trim(($rf['nouvel_prenom'] ?? '') . ' ' . ($rf['nouvel_nom'] ?? ''))) ?: '<span class="text-muted">—</span>' ?></td>
                        <td><?= $rf['cause_libelle'] ? '<span class="badge bg-light text-dark border">' . h($rf['cause_libelle']) . '</span>' : '<span class="text-muted">non précisée</span>' ?></td>
                        <td class="small"><?= h(trim(($rf['auteur_prenom'] ?? '') . ' ' . ($rf['auteur_nom'] ?? ''))) ?: '<span class="text-muted">—</span>' ?></td>
                        <td class="small"><?= $rf['Commentaire'] ? nl2br(h($rf['Commentaire'])) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($voitHistorique): ?>
    <div class="tab-pane fade" id="historique">
        <div class="timeline">
            <?php foreach ($journal as $j): ?>
                <div class="timeline-item">
                    <div class="small text-muted"><?= formatDate($j['Date_action'], true) ?></div>
                    <div><strong><?= h(($j['Prenom'] ?? 'Système') . ' ' . ($j['Nom'] ?? '')) ?></strong> — <?= h($j['Detail'] ?: $j['Action']) ?></div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($journal)): ?><p class="text-muted">Aucun historique.</p><?php endif; ?>
        </div>

        <?php if (count($journalDossier) > count($journal)): ?>
        <details class="mt-3">
            <summary class="text-primary" style="cursor:pointer;">
                Historique complet du dossier <span class="badge bg-secondary"><?= count($journalDossier) ?></span>
                <span class="text-muted small">— activité racine et tous les éléments rattachés</span>
            </summary>
            <div class="timeline mt-3">
                <?php foreach ($journalDossier as $j): ?>
                    <div class="timeline-item">
                        <div class="small text-muted"><?= formatDate($j['Date_action'], true) ?></div>
                        <div>
                            <strong><?= h(($j['Prenom'] ?? 'Système') . ' ' . ($j['Nom'] ?? '')) ?></strong> — <?= h($j['Detail'] ?: $j['Action']) ?>
                            <?php if ((int)$j['ID_activites'] !== $id && $j['item_libelle']): ?>
                                <span class="badge bg-light text-dark border ms-1"><?= h(typeActiviteLabel($j['item_types'] ?? '')) ?> : <?= h($j['item_libelle']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </details>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($estResponsable || $estAdmin): ?>
<div class="modal fade" id="modalEdit" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="modifier_tache">
                <div class="modal-header"><h5 class="modal-title">Modifier la tâche</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Libellé *</label>
                            <input type="text" name="Libelle" class="form-control" value="<?= h($tache['Libelle']) ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Note</label>
                            <textarea name="Observation" class="form-control" rows="3" placeholder="Faire une note sur cette activité, sous-activité ou tâche..."><?= h($tache['Observation'] ?? '') ?></textarea>
                            <div class="form-text">Faites une note sur les activités, sous-activités ou tâches.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Produit attendu</label>
                            <input type="text" name="Produit" class="form-control" value="<?= h($tache['Produit'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Type</label>
                            <select name="Types" class="form-select">
                                <option value="activite" <?= $tache['Types'] === 'activite' ? 'selected' : '' ?>>Activité</option>
                                <option value="sous-activite" <?= $tache['Types'] === 'sous-activite' ? 'selected' : '' ?>>Sous-activité</option>
                                <option value="tache" <?= $tache['Types'] === 'tache' ? 'selected' : '' ?>>Tâche</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Date début *</label>
                            <input type="date" name="Date_debut" class="form-control" value="<?= h($tache['Date_debut']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Date fin *</label>
                            <input type="date" name="Date_fin" class="form-control" value="<?= h($tache['Date_fin']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <?php $prioriteValeur = $tache['Priorite']; include __DIR__ . '/includes/champ_priorite.php'; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Statut</label>
                            <select name="Statut" class="form-select" <?= $tacheEnfants ? 'disabled' : '' ?>>
                                <?php foreach (['a_faire','en_cours','en_attente','termine','en_retard','annule'] as $s): ?>
                                    <option value="<?= $s ?>" <?= $tache['Statut'] === $s ? 'selected' : '' ?>><?= statutLabel($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($tacheEnfants): ?>
                                <input type="hidden" name="Statut" value="<?= h($tache['Statut']) ?>">
                                <div class="form-text">Recalculé automatiquement à partir des <?= count($tacheEnfants) ?> élément(s) rattaché(s) : <strong>Terminé</strong> à 100 %, sinon <strong>En cours</strong>.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" class="btn btn-primary">Enregistrer</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function majBesoinProfil() {
    var t = document.getElementById('besoinType');
    var w = document.getElementById('besoinProfilWrap');
    if (!t || !w) return;
    var humain = t.value === 'humain';
    w.style.display = humain ? '' : 'none';
    var champ = document.getElementById('besoinProfil');
    if (champ) { champ.required = humain; if (!humain) champ.value = ''; }
}
document.addEventListener('DOMContentLoaded', majBesoinProfil);
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
